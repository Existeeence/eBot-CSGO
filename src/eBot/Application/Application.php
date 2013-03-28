<?php

/**
 * eBot - A bot for match management for CS:GO
 * @license     http://creativecommons.org/licenses/by/3.0/ Creative Commons 3.0
 * @author      Julien Pardons <julien.pardons@esport-tools.net>
 * @version     3.0
 * @date        21/10/2012
 */

namespace eBot\Application;

use eTools\Utils\Logger;
use eTools\Application\AbstractApplication;
use eTools\Socket\UDPSocket as Socket;
use eBot\Manager\MessageManager;
use eBot\Manager\PluginsManager;
use eBot\Manager\MatchManager;
use eBot\Config\Config;

class Application extends AbstractApplication {

    const VERSION = "3.0";

    private $socket = null;
    public $websocket = null;
    private $clientsConnected = false;

    public function run() {
        // Loading Logger instance
        Logger::getInstance();
        Logger::log($this->getName());

        // Loading eBot configuration
        Logger::log("Loading config");
        Config::getInstance()->printConfig();

        // Initializing database
        $this->initDatabase();

        // Registring components
        Logger::log("Registering MatchManager");
        MatchManager::getInstance();

        Logger::log("Registering Messages");
        MessageManager::createFromConfigFile();

        Logger::log("Registering PluginsManager");
        PluginsManager::getInstance();

        // Starting application
        Logger::log("Starting eBot Application");

        try {
            $this->socket = new Socket(Config::getInstance()->getBot_ip(), Config::getInstance()->getBot_port());
        } catch (Exception $ex) {
            Logger::error("Unable to bind socket");
            die();
        }

        try {
            $this->websocket['match'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/match");
            $this->websocket['match']->open();
            $this->websocket['rcon'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/rcon");
            $this->websocket['rcon']->open();
            $this->websocket['logger'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/logger");
            $this->websocket['logger']->open();
            $this->websocket['livemap'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/livemap");
            $this->websocket['livemap']->open();
            $this->websocket['aliveCheck'] = new \WebSocket("ws://" . \eBot\Config\Config::getInstance()->getBot_ip() . ":" . (\eBot\Config\Config::getInstance()->getBot_port()) . "/alive");
            $this->websocket['aliveCheck']->open();
        } catch (Exception $ex) {
            Logger::error("Unable to create Websocket.");
            die();
        }

        PluginsManager::getInstance()->startAll();

        $time = time();
        while (true) {
            $data = $this->socket->recvfrom($ip);
            if ($data) {
                if (!preg_match("/L+\s+\d+\/\d+\/\d+/", $data)) {
                    if ($data == '__true__') {
                        $this->clientsConnected = true;
                    } elseif ($data == '__false__') {
                        $this->clientsConnected = false;
                    } elseif ($data == '__aliveCheck__') {
                        $this->websocket['aliveCheck']->sendData('__isAlive__');
                    } else {
                        $data = json_decode($data, true);
                        $authkey = \eBot\Manager\MatchManager::getInstance()->getAuthkey($data[1]);
                        $text = \eTools\Utils\Encryption::decrypt($data[0], $authkey, 256);
                        if ($text) {
                            if (preg_match("!^(?<id>\d+) stopNoRs (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStopNoRs();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => 'stop', 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) stop (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStop();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => 'stop', 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) executeCommand (?<ip>\d+\.\d+\.\d+\.\d+\:\d+) (?<command>.*)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminExecuteCommand($preg["command"]);
                                    if ($reply) {
                                        $send = json_encode(array('id' => $preg["id"], 'content' => $reply));
                                        $this->websocket['rcon']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) passknife (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminPassKnife();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) forceknife (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceKnife();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) forceknifeend (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceKnifeEnd();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) forcestart (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminForceStart();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) stopback (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStopBack();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) pauseunpause (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminPauseUnpause();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) fixsides (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminFixSides();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) streamerready (?<ip>\d+\.\d+\.\d+\.\d+\:\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminStreamerReady();
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } elseif (preg_match("!^(?<id>\d+) goBackRounds (?<ip>\d+\.\d+\.\d+\.\d+\:\d+) (?<round>\d+)$!", $text, $preg)) {
                                $match = \eBot\Manager\MatchManager::getInstance()->getMatch($preg["ip"]);
                                if ($match) {
                                    $reply = $match->adminGoBackRounds($preg['round']);
                                    if ($reply) {
                                        $send = json_encode(array('message' => 'button', 'content' => $match->getStatus(), 'id' => $preg["id"]));
                                        $this->websocket['match']->sendData($send);
                                    }
                                } else {
                                    Logger::error($preg["ip"] . " is not managed !");
                                }
                            } else {
                                Logger::error($text . " not managed");
                            }
                        }
                    }
                } else {
                    $line = substr($data, 7);

                    if (\eBot\Manager\MatchManager::getInstance()->getMatch($ip)) {
                        file_put_contents(APP_ROOT . "/logs/$ip", $line, FILE_APPEND);
                        $line = trim(substr($line, 23));
                        \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->processMessage($line);
                        if ($this->clientsConnected) {
                            $line = substr($data, 7, strlen($data) - 8);
                            file_put_contents(Logger::getInstance()->getLogPathAdmin() . "/logs_" . \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->getMatchId(), $line, FILE_APPEND);
                            $send = json_encode(array('id' => \eBot\Manager\MatchManager::getInstance()->getMatch($ip)->getMatchId(), 'content' => $line));
                            $this->websocket['logger']->sendData($send);
                        }
                    }
                }
            }
            if ($time + 5 < time()) {
                $time = time();
                $this->websocket['match']->send(json_encode(array("message" => "ping")));
                $this->websocket['logger']->send(json_encode(array("message" => "ping")));
                $this->websocket['rcon']->send(json_encode(array("message" => "ping")));
                $this->websocket['livemap']->send(json_encode(array("message" => "ping")));
                $this->websocket['aliveCheck']->send(json_encode(array("message" => "ping")));
            }

            \eBot\Manager\MatchManager::getInstance()->sendPub();
            \eTools\Task\TaskManager::getInstance()->runTask();
        }
    }

    private function initDatabase() {
        $conn = @\mysql_connect(Config::getInstance()->getMysql_ip(), Config::getInstance()->getMysql_user(), Config::getInstance()->getMysql_pass());
        if (!$conn) {
            Logger::error("Can't login into database " . Config::getInstance()->getMysql_user() . "@" . Config::getInstance()->getMysql_ip());
            exit(1);
        }

        if (!\mysql_select_db(Config::getInstance()->getMysql_base(), $conn)) {
            Logger::error("Can't select database " . Config::getInstance()->getMysql_base());
            exit(1);
        }
    }

    public function getName() {
        return "eBot CS:Go version " . $this->getVersion();
    }

    public function getVersion() {
        return self::VERSION;
    }

    public function getSocket() {
        return $this->socket;
    }

    public function getWebSocket($room) {
        return $this->websocket[$room];
    }

}

?>

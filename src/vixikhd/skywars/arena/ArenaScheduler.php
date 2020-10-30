<?php

/**
 * Copyright 2018-2020 GamakCZ
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

declare(strict_types=1);

namespace vixikhd\skywars\arena;

use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\level\Level;
use pocketmine\level\particle\AngryVillagerParticle;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\level\particle\DustParticle;
use pocketmine\level\Position;
use pocketmine\level\sound\AnvilUseSound;
use pocketmine\level\sound\ClickSound;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\tile\Sign;
use pocketmine\utils\TextFormat as TE;
use Scoreboards\Scoreboards;
use vixikhd\skywars\entity\FireworksRocket;
use vixikhd\skywars\item\Fireworks;
use vixikhd\skywars\math\Time;
use vixikhd\skywars\math\Vector3;
use vixikhd\skywars\SkyWars;

/**
 * Class ArenaScheduler
 * @package skywars\arena
 */
class ArenaScheduler extends Task {

    /** @var Arena $plugin */
    protected $plugin;

    /** @var int $startTime */
    public $startTime = 30;

    /** @var float|int $gameTime */
    public $gameTime = 20 * 60;

    /** @var int $restartTime */
    public $restartTime = 6;

     public $time = 0;

    /** @var array $restartData */
    public $restartData = [];
    private $r;

    /**
     * ArenaScheduler constructor.
     * @param Arena $plugin
     */
    public function __construct(Arena $plugin) {
        $this->plugin = $plugin;
        $this->r = 0;
    }

    /**
     * @param int $currentTick
     */
    public function onRun(int $currentTick) {
        $this->reloadSign();

        if($this->plugin->setup) return;

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= 2) {
                    $this->plugin->broadcastMessage("§l§a» §r§eStarting in §l§c" . Time::calculateTime($this->startTime) . " §r§eseconds §l§a«", Arena::MSG_TIP);
                    $this->startTime--;
                    if($this->startTime == 0) {
                        $this->plugin->startGame();
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound(new AnvilUseSound($player->asVector3()));
                        }
                    }
                    else {
                        foreach ($this->plugin->players as $player) {
                            $this->plugin->level->addSound(new ClickSound($player->asVector3()));
                        }
                    }
                }
                else {
                    $this->plugin->broadcastMessage("§l§c» §r§6Waiting for players... §l§c«", Arena::MSG_TIP);
                    $this->startTime = 30;
                }
                foreach ($this->plugin->players as $player) {
                    $this->createScoreboard($player);
                    $this->time++;
                }
                break;
            case Arena::PHASE_GAME:
                //$this->plugin->broadcastMessage("§a> There are " . count($this->plugin->players) . " players, time to end: " . Time::calculateTime($this->gameTime) . "", Arena::MSG_TIP);
                switch ($this->gameTime) {
                    case 15 * 60:
                        $this->plugin->broadcastMessage("§l§6» §r§aAll chests will be refilled in §l§c5§r§a min.");
                        break;
                    case 11 * 60:
                        $this->plugin->broadcastMessage("§l§6» §r§aAll chests will be refilled in §l§c1§r§a min.");
                        break;
                    case 10 * 60:
                        $this->plugin->broadcastMessage("§l§6» §r§aAll chests are refilled.");
                        /*$this->plugin->broadcastMessage("§l§6» §r§aSe ha agregado una brujula rastreadora en tu inventario!");
                        foreach ($this->plugin->players as $player) {
                            $compass = Item::get(Item::COMPASS, 0, 1);
                            $player->getInventory()->addItem($compass);
                        }*/
                        break;
                }
                if($this->plugin->checkEnd()) $this->plugin->startRestart();
                $this->gameTime--;
                break;
            case Arena::PHASE_RESTART:

                foreach ($this->plugin->players as $player) {
                    $player->getInventory()->clearAll();
                    $player->getArmorInventory()->clearAll();
                    $player->getCursorInventory()->clearAll();
                    $x = $player->getX();
                    $y = $player->getY();
                    $z = $player->getZ();
                    $level = $player->getLevel();
                    $level->addSound(new AnvilUseSound(new Vector3($x, $y + 1, $z)));
                    $player->setFood(20);
                    $player->setHealth(20);
                    $player->setAllowFlight(true);
                    $player->setFlying(true);
                    $player->setGamemode($this->plugin->plugin->getServer()->getDefaultGamemode());

                    $this->Lightning($player);
                }
                $this->plugin->broadcastMessage("§l§c» §r§eRestarting in §l§c{$this->restartTime} §r§eseconds. §l§c«", Arena::MSG_TIP);
                $this->restartTime--;
                foreach ($this->plugin->players as $player) {
                    $x = $player->getX();
                    $y = $player->getY();
                    $z = $player->getZ();
                    $level = $player->getLevel();
                    $level->addParticle(new AngryVillagerParticle(new Vector3($x+1.00, $y+1.00, $z+1.00)));
                    $level->addParticle(new AngryVillagerParticle(new Vector3($x-1.00, $y-1.00, $z-1.00)));
                    $level->addParticle(new AngryVillagerParticle(new Vector3($x+1.50, $y+1.50, $z+1.50)));
                    $level->addParticle(new AngryVillagerParticle(new Vector3($x-1.50, $y-1.50, $z-1.50)));
                }

                switch ($this->restartTime) {
                    case 0:

                        foreach ($this->plugin->players as $player) {
                            $player->teleport($this->plugin->plugin->getServer()->getDefaultLevel()->getSpawnLocation());

                            $player->getInventory()->clearAll();
                            $player->getArmorInventory()->clearAll();
                            $player->getCursorInventory()->clearAll();

                            $player->setFood(20);
                            $player->setHealth(20);
                            $player->setAllowFlight(false);
                            $player->setFlying(false);
                            $player->setGamemode($this->plugin->plugin->getServer()->getDefaultGamemode());
                        }
                        $this->plugin->loadArena(true);
                        $this->reloadTimer();
                        break;
                }
                break;
        }
    }

    public function Lightning(Player $player) :void
    {
        $light = new AddActorPacket();
        $light->type = "minecraft:lightning_bolt";
        $light->entityRuntimeId = Entity::$entityCount++;
        $light->metadata = [];
        $light->motion = null;
        $light->yaw = $player->getYaw();
        $light->pitch = $player->getPitch();
        $light->position = new Vector3($player->getX()+1, $player->getY(), $player->getZ());
        Server::getInstance()->broadcastPacket($player->getLevel()->getPlayers(), $light);
        $block = $player->getLevel()->getBlock($player->getPosition()->floor()->down());
        $particle = new DestroyBlockParticle(new Vector3($player->getX(), $player->getY(), $player->getZ()), $block);
        $player->getLevel()->addParticle($particle);
        $sound = new PlaySoundPacket();
        $sound->soundName = "ambient.weather.thunder";
        $sound->x = $player->getX();
        $sound->y = $player->getY();
        $sound->z = $player->getZ();
        $sound->volume = 3;
        $sound->pitch = 1;
        Server::getInstance()->broadcastPacket($player->getLevel()->getPlayers(), $sound);
    }

    public function reloadSign() {
        if(!is_array($this->plugin->data["joinsign"]) || empty($this->plugin->data["joinsign"])) return;

        $signPos = Position::fromObject(Vector3::fromString($this->plugin->data["joinsign"][0]), $this->plugin->plugin->getServer()->getLevelByName($this->plugin->data["joinsign"][1]));

        if(!$signPos->getLevel() instanceof Level || is_null($this->plugin->level)) return;

        $signText = [
            "§e§lSkyWars",
            "§9[ §b? / ? §9]",
            "§6Setup",
            "§6Wait few sec..."
        ];

        if($signPos->getLevel()->getTile($signPos) === null) return;

        if($this->plugin->setup || $this->plugin->level === null) {
            /** @var Sign $sign */
            $sign = $signPos->getLevel()->getTile($signPos);
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
            return;
        }

        $signText[1] = "§9[ §b" . count($this->plugin->players) . " / " . $this->plugin->data["slots"] . " §9]";

        switch ($this->plugin->phase) {
            case Arena::PHASE_LOBBY:
                if(count($this->plugin->players) >= $this->plugin->data["slots"]) {
                    $signText[2] = "§6Full";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                else {
                    $signText[2] = "§aJoin";
                    $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                }
                break;
            case Arena::PHASE_GAME:
                $signText[2] = "§5InGame";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
            case Arena::PHASE_RESTART:
                $signText[2] = "§cRestarting...";
                $signText[3] = "§8Map: §7{$this->plugin->level->getFolderName()}";
                break;
        }

        /** @var Sign $sign */
        $sign = $signPos->getLevel()->getTile($signPos);
        if($sign instanceof Sign) // Chest->setText() doesn't work :D
            $sign->setText($signText[0], $signText[1], $signText[2], $signText[3]);
    }

    public function reloadTimer() {
        $this->startTime = 30;
        $this->gameTime = 20 * 60;
        $this->restartTime = 10;
    }

    public function createScoreboard(Player $player) : void
    {
        $api = Scoreboards::getInstance();
        $plugin = SkyWars::getInstance()->getServer()->getPluginManager()->getPlugin("PreciseCpsCounter");
        $cps = $plugin->getCPS($player);
        $ping = $player->getPing();
        $pl = count($this->plugin->players);
        $max = $this->plugin->data["slots"];
        $map = $this->plugin->level->getFolderName();
        $api->new($player, "game", "§l§6SkyWars");
        $api->setLine($player, 8, TE::RED. "§7────────────────");
        $api->setLine($player, 7, TE::RESET. " §6Map: §f". $map);
        $api->setLine($player, 6, TE::RESET. " §6Jugadores: §a" . $pl. "§7/§a". $max);
        $api->setLine($player, 5, TE::GRAY. "§a");
        $api->setLine($player, 4, TE::RESET. " §6Ping: §f" . $ping);
        $api->setLine($player, 3, TE::RESET. " §6CPS: §f$cps");
        $api->setLine($player, 2, TE::RESET. "§7────────────────");
        $api->setLine($player, 1, TE::RESET. " §6play.kingsserver.net 25618");
        $api->getObjectiveName($player);
    }
}

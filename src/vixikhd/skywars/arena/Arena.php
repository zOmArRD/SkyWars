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

use pocketmine\block\Block;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerExhaustEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\inventory\ChestInventory;
use pocketmine\item\Armor;
use pocketmine\item\enchantment\Enchantment;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\Item;
use pocketmine\item\ItemIds;
use pocketmine\item\Sword;
use pocketmine\level\Level;
use pocketmine\level\particle\DestroyBlockParticle;
use pocketmine\level\Position;
use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\tile\Chest;
use pocketmine\tile\Tile;
use vixikhd\skywars\event\PlayerArenaWinEvent;
use vixikhd\skywars\math\Vector3;
use vixikhd\skywars\provider\ArmorTypes;
use vixikhd\skywars\SkyWars;
use Scoreboards\Scoreboards;

/**
 * Class Arena
 * @package skywars\arena
 */
class Arena implements Listener {

    const MSG_MESSAGE = 0;
    const MSG_TIP = 1;
    const MSG_POPUP = 2;
    const MSG_TITLE = 3;

    const PHASE_LOBBY = 0;
    const PHASE_GAME = 1;
    const PHASE_RESTART = 2;

    /** @var SkyWars $plugin */
    public $plugin;

    /** @var ArenaScheduler $scheduler */
    public $scheduler;

    /** @var MapReset $mapReset */
    public $mapReset;

    /** @var int $phase */
    public $phase = 0;

    /** @var array $data */
    public $data = [];

    /** @var bool $setting */
    public $setup = false;

    /** @var Player[] $players */
    public $players = [];

    /** @var Player[] $toRespawn */
    public $toRespawn = [];

    /** @var Level $level */
    public $level = null;

    /**
     * Arena constructor.
     * @param SkyWars $plugin
     * @param array $arenaFileData
     */
    public function __construct(SkyWars $plugin, array $arenaFileData) {
        $this->plugin = $plugin;
        $this->data = $arenaFileData;
        $this->setup = !$this->enable(\false);

        $this->plugin->getScheduler()->scheduleRepeatingTask($this->scheduler = new ArenaScheduler($this), 20);

        if($this->setup) {
            if(empty($this->data)) {
                $this->createBasicData();
            }
        }
        else {
            $this->loadArena();
        }
    }

    /**
     * @param Player $player
     */
    public function joinToArena(Player $player) {
        if(!$this->data["enabled"]) {
            $player->sendMessage("§l§c» §r§7This arena is not enabled.");
            return;
        }

        if(count($this->players) >= $this->data["slots"]) {
            $player->sendMessage("§l§c» §r§7This game is full");
            return;
        }

        if($this->inGame($player)) {
            $player->sendMessage("§l§c» §r§7You are already in game!");
            return;
        }

        $selected = false;
        for($lS = 1; $lS <= $this->data["slots"]; $lS++) {
            if(!$selected) {
                if(!isset($this->players[$index = "spawn-{$lS}"])) {
                    $player->teleport(Position::fromObject(Vector3::fromString($this->data["spawns"][$index]), $this->level));
                    $this->players[$index] = $player;
                    $selected = true;
                }
            }
        }

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setAllowFlight(false);
        $player->setFlying(false);


        $player->setGamemode($player::ADVENTURE);
        $player->setHealth(20);
        $player->setFood(20);

        $this->broadcastMessage("§7[§a+§7] §8{$player->getName()} §7has joined! (".count($this->players)."/{$this->data["slots"]})");
    }

    /**
     * @param Player $player
     * @param string $quitMsg
     * @param bool $death
     */
    public function disconnectPlayer(Player $player, string $quitMsg = "", bool $death = false) {
        switch ($this->phase) {
            case Arena::PHASE_LOBBY:
                $index = "";
                foreach ($this->players as $i => $p) {
                    if($p->getId() == $player->getId()) {
                        $index = $i;
                    }
                }
                if($index != "") {
                    unset($this->players[$index]);
                }
                break;
            default:
                unset($this->players[$player->getName()]);
                break;
        }

        $player->removeAllEffects();

        $player->setGamemode($this->plugin->getServer()->getDefaultGamemode());

        $player->setHealth(20);
        $player->setFood(20);

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $api = Scoreboards::getInstance();
        $api->remove($player);

        if(!$death) {
            $this->broadcastMessage("§7[§a§7] §8{$player->getName()} §7left the game (".count($this->players)."/{$this->data["slots"]})");
        }

        if($quitMsg != "") {
            $player->sendMessage("§l§a»§r§f $quitMsg");
        }
    }


    public function startGame() {
        $players = [];
        foreach ($this->players as $player) {
            $players[$player->getName()] = $player;
            $player->setGamemode($player::SURVIVAL);
        }


        $this->players = $players;
        $this->phase = 1;

        $this->fillChests();

        $this->broadcastMessage("§l§a» §r§gThe game has started! §l§a«", self::MSG_MESSAGE);
    }

    public function startRestart() {
        $player = null;
        foreach ($this->players as $p) {
            $player = $p;
        }

        if($player === null || (!$player instanceof Player) || (!$player->isOnline())) {
            $this->phase = self::PHASE_RESTART;
            return;
        }

        $player->addTitle("§l§c» §6GAME OVER §c«");
        $this->plugin->getServer()->getPluginManager()->callEvent(new PlayerArenaWinEvent($this->plugin, $player, $this));
        $this->plugin->getManager()->setWins($player);
        //$this->plugin->getServer()->broadcastMessage("§l§6» §r§f{$player->getName()} §awon the game at§f {$this->level->getFolderName()}§a!");
        $this->phase = self::PHASE_RESTART;
    }

    /**
     * @param Player $player
     * @return bool $isInGame
     */
    public function inGame(Player $player): bool {
        switch ($this->phase) {
            case self::PHASE_LOBBY:
                $inGame = false;
                foreach ($this->players as $players) {
                    if($players->getId() == $player->getId()) {
                        $inGame = true;
                    }
                }
                return $inGame;
            default:
                return isset($this->players[$player->getName()]);
        }
    }

    /**
     * @param string $message
     * @param int $id
     * @param string $subMessage
     */
    public function broadcastMessage(string $message, int $id = 0, string $subMessage = "") {
        foreach ($this->players as $player) {
            switch ($id) {
                case self::MSG_MESSAGE:
                    $player->sendMessage($message);
                    break;
                case self::MSG_TIP:
                    $player->sendTip($message);
                    break;
                case self::MSG_POPUP:
                    $player->sendPopup($message);
                    break;
                case self::MSG_TITLE:
                    $player->addTitle($message, $subMessage);
                    break;
            }
        }
    }

    /**
     * @return bool $end
     */
    public function checkEnd(): bool {
        return count($this->players) <= 1;
    }

    public function fillChests(): void
    {
        $level = $this->level;
        if (!$level instanceof Level) return;
        foreach ($level->getTiles() as $tile) {
            if ($tile instanceof Chest) {
                $inventory = $tile->getInventory();
                $inventory->clearAll();
                $contents = $this->getChestContents();
                foreach (array_shift($contents) as $key => $val) {
                    if (gettype($val[0]) === "string") {
                        $parts = explode(":", $val[0]);
                        $item = Item::get(intval($parts[0]), intval($parts[1]), $val[1]);
                    } else {
                        $item = Item::get($val[0], 0, $val[1]);
                    }
                    $item = $this->enchantItem($item);
                    $inventory->setItem($key, $item, false);
                }

                $inventory->sendContents($inventory->getViewers());
            }
        }
    }

    public function enchantItem($item): Item
    {
        $armorEnchantments = [
            Enchantment::PROTECTION => 1,
            Enchantment::FIRE_PROTECTION => 1,
        ];

        $swordEnchantments = [
            Enchantment::FIRE_ASPECT => 1,
            Enchantment::SHARPNESS => 1,
        ];

        a:
        if ($item instanceof Armor) {
            $enchantment = array_rand($armorEnchantments);
            if ($b = rand(1, 2) == 2) {
                $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($enchantment), $armorEnchantments[$enchantment]));
            }
            if ($b == 2 && rand(1, 2) == 2) {
                goto a;//second enchantment
            }
        }
        if ($item instanceof Sword) {
            $enchantment = array_rand($swordEnchantments);
            if ($b = rand(1, 2) == 2) {
                $item->addEnchantment(new EnchantmentInstance(Enchantment::getEnchantment($enchantment), $swordEnchantments[$enchantment]));
            }
            if ($b == 2 && rand(1, 2) == 2) {
                goto a;//second enchantment
            }
        }
        return $item;
    }

    public function getChestContents(): array//TODO: **rewrite** this and let the owner decide the contents of the chest
    {
        $items = [
            "armor" => [
                [
                    Item::IRON_HELMET,
                    Item::IRON_CHESTPLATE,
                    Item::IRON_LEGGINGS,
                    Item::IRON_BOOTS
                ],
                [
                    Item::DIAMOND_HELMET,
                    Item::DIAMOND_CHESTPLATE,
                    Item::DIAMOND_LEGGINGS,
                    Item::DIAMOND_BOOTS
                ]
            ],
            "weapon" => [
                [
                    Item::FISHING_ROD
                ],
                [
                    Item::IRON_SWORD,
                    Item::IRON_AXE
                ],
                [
                    Item::DIAMOND_SWORD,
                    Item::DIAMOND_AXE
                ]
            ],
            "food" => [
                [
                    Item::RAW_PORKCHOP,
                    Item::RAW_CHICKEN,
                    Item::MELON_SLICE,
                    Item::COOKIE
                ],
                [
                    Item::APPLE,
                    Item::GOLDEN_APPLE
                ],
                [
                    Item::BEETROOT_SOUP,
                    Item::BREAD,
                    Item::BAKED_POTATO
                ],
                [
                    Item::MUSHROOM_STEW,
                    Item::COOKED_CHICKEN
                ],
                [
                    Item::COOKED_PORKCHOP,
                    Item::STEAK,
                    Item::PUMPKIN_PIE
                ],
            ],
            "throwable" => [
                [
                    Item::BOW,
                    Item::ARROW
                ],
                [
                    Item::SNOWBALL
                ],
                [
                    Item::EGG
                ]
            ],
            "block" => [
                Item::STONE,
                Item::WOODEN_PLANKS,
                Item::COBBLESTONE,
                Item::DIRT
            ],
            "other" => [
                [
                    Item::IRON_PICKAXE,
                    Item::DIAMOND_PICKAXE
                ],
                [
                    Item::STICK,
                    Item::STRING
                ]
            ],
            "pots" => ["438:14", "438:22"],
            "cubos" => ["325:10", "325:8"],
            "pearl" => ["368:0"],
        ];

        $templates = [];
        for ($i = 0; $i < 10; $i++) {//TODO: understand wtf is the stuff in here doing

            $armorq = mt_rand(0, 1);
            $armortype = $items["armor"][array_rand($items["armor"])];

            $armor1 = [$armortype[array_rand($armortype)], 1];
            if ($armorq) {
                $armortype = $items["armor"][array_rand($items["armor"])];
                $armor2 = [$armortype[array_rand($armortype)], 1];
                $armor3 = [$armortype[array_rand($armortype)], 1];
                $armor4 = [$armortype[array_rand($armortype)], 1];
            } else {
                $armor2 = [0, 1];
                $armor3 = [0, 1];
                $armor4 = [0, 1];
            }
            $weapontype = $items["weapon"][array_rand($items["weapon"])];
            $weapon = [$weapontype[array_rand($weapontype)], 1];
            $cuboss = [$items["cubos"][array_rand($items["cubos"])], 1];
            $pots = [$items["pots"][array_rand($items["pots"])], 1];
            $pearls = [$items["pearl"][array_rand($items["pearl"])], 1];
            $ftype = $items["food"][array_rand($items["food"])];
            $food = [$ftype[array_rand($ftype)], mt_rand(2, 5)];
            if (mt_rand(0, 1)) {
                $tr = $items["throwable"][array_rand($items["throwable"])];
                if (count($tr) === 2) {
                    $throwable1 = [$tr[1], mt_rand(10, 20)];
                    $throwable2 = [$tr[0], 1];
                } else {
                    $throwable1 = [0, 1];
                    $throwable2 = [$tr[0], mt_rand(5, 10)];
                }
                $other = [0, 1];
            } else {
                $throwable1 = [0, 1];
                $throwable2 = [0, 1];
                $ot = $items["other"][array_rand($items["other"])];
                $other = [$ot[array_rand($ot)], 1];
            }
            $block = [$items["block"][array_rand($items["block"])], 64];
            $contents = [$armor1, $armor2, $armor3, $armor4, $weapon, $weapon, $weapon, $pots, $pots, $pearls, $cuboss, $food, $throwable1, $throwable2, $block, $other];
            shuffle($contents);
            $fcontents = [
                mt_rand(0, 1) => array_shift($contents),
                mt_rand(2, 3) => array_shift($contents),
                mt_rand(4, 5) => array_shift($contents),
                mt_rand(7, 8) => array_shift($contents),
                mt_rand(9, 10) => array_shift($contents),
                mt_rand(12, 14) => array_shift($contents),
                mt_rand(15, 17) => array_shift($contents),
                mt_rand(18, 19) => array_shift($contents),
                mt_rand(21, 23) => array_shift($contents),
                mt_rand(24, 26) => array_shift($contents),
            ];
            $templates[] = $fcontents;
        }
        shuffle($templates);
        return $templates;
    }


    /**
     * @param PlayerMoveEvent $event
     */
    public function onMove(PlayerMoveEvent $event) {
        if($this->phase != self::PHASE_LOBBY) return;
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            $index = null;
            foreach ($this->players as $i => $p) {
                if($p->getId() == $player->getId()) {
                    $index = $i;
                }
            }
            if($event->getPlayer()->asVector3()->distance(Vector3::fromString($this->data["spawns"][$index])) > 1) {
                // $event->setCancelled() wont work
                $player->teleport(Vector3::fromString($this->data["spawns"][$index]));
            }
        }
    }

    /**
     * @param PlayerExhaustEvent $event
     */
    public function onExhaust(PlayerExhaustEvent $event) {
        $player = $event->getPlayer();

        if(!$player instanceof Player) return;

        if($this->inGame($player) && $this->phase == self::PHASE_LOBBY) {
            $event->setCancelled(true);
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onInteract(PlayerInteractEvent $event) {
        $player = $event->getPlayer();
        $block = $event->getBlock();

        if($this->inGame($player) && $event->getBlock()->getId() == Block::CHEST && $this->phase == self::PHASE_LOBBY) {
            $event->setCancelled(true);
            return;
        }

        if(!$block->getLevel()->getTile($block) instanceof Tile) {
            return;
        }

        $signPos = Position::fromObject(Vector3::fromString($this->data["joinsign"][0]), $this->plugin->getServer()->getLevelByName($this->data["joinsign"][1]));

        if((!$signPos->equals($block)) || $signPos->getLevel()->getId() != $block->getLevel()->getId()) {
            return;
        }

        if($this->phase == self::PHASE_GAME) {
            $player->sendMessage("§l§c» §r§7This arena is at stake.");
            return;
        }
        if($this->phase == self::PHASE_RESTART) {
            $player->sendMessage("§l§c» §r§7This sand is reestablishing.");
            return;
        }

        if($this->setup) {
            return;
        }

        $this->joinToArena($player);
    }


    public function onDamage(EntityDamageEvent $event)
    {
        $entity = $event->getEntity();

        if($entity instanceof Player) {
            if ($this->inGame($entity)) {
                if ($event->getFinalDamage() > $entity->getHealth()) {
                    $this->kill($entity);
                    $event->setCancelled();
                }
            }
        }
    }

    public function kill(Player $player){
        $this->Lightning($player);
        foreach ($player->getInventory() as $item) {
            $player->dropItem($item);
        }
        $this->disconnectPlayer($player, "", true);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setGamemode(3);
        $player->setFlying(true);
        $player->setAllowFlight(true);
        $player->setInvisible(true);

        $player->sendMessage("§6Ahora estas en modo espectador, para ir al lobby poner /hub!");
        //$player->teleport($this->plugin->getServer()->getDefaultLevel()->getSafeSpawn());
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
        $light->position = new Vector3($player->getX(), $player->getY(), $player->getZ());
        Server::getInstance()->broadcastPacket($player->getLevel()->getPlayers(), $light);
        $block = $player->getLevel()->getBlock($player->getPosition()->floor()->down());
        $particle = new DestroyBlockParticle(new Vector3($player->getX(), $player->getY(), $player->getZ()), $block);
        $player->getLevel()->addParticle($particle);
        $sound = new PlaySoundPacket();
        $sound->soundName = "ambient.weather.thunder";
        $sound->x = $player->getX();
        $sound->y = $player->getY();
        $sound->z = $player->getZ();
        $sound->volume = 1;
        $sound->pitch = 1;
        Server::getInstance()->broadcastPacket($player->getLevel()->getPlayers(), $sound);
    }

    /**
     * @param PlayerDeathEvent $event
     */
    public function onDeath(PlayerDeathEvent $event) {
        $player = $event->getPlayer();
        if($this->inGame($player)) {
            $this->broadcastMessage("§6 {$this->plugin->getServer()->getLanguage()->translate($event->getDeathMessage())} §7(" . count($this->players) . "/{$this->data["slots"]})");
            $event->setDeathMessage("");
            $event->setDrops([]);
            $this->kill($player);
        }
    }

    /**
    public function onRespawn(PlayerRespawnEvent $event) {
        $player = $event->getPlayer();
        if(isset($this->toRespawn[$player->getName()])) {
            $event->setRespawnPosition($this->plugin->getServer()->getDefaultLevel()->getSpawnLocation());
            unset($this->toRespawn[$player->getName()]);
        }
    }*/

    /**
     * @param PlayerQuitEvent $event
     */
    public function onQuit(PlayerQuitEvent $event) {
        if($this->inGame($event->getPlayer())) {
            $this->disconnectPlayer($event->getPlayer());
        }
    }

    /**
     * @param EntityLevelChangeEvent $event
     */
    public function onLevelChange(EntityLevelChangeEvent $event) {
        $player = $event->getEntity();
        if(!$player instanceof Player) return;
        $player->setInvisible(false);
        $player->setAllowFlight(false);
        $player->setFlying(false);
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->setGamemode(0);
        if($this->inGame($player)) {
            $this->disconnectPlayer($player, "You have successfully left the game!");
        }
    }

    public function onRespawn(PlayerRespawnEvent $event){
        $player = $event->getPlayer();

        $player->setInvisible(false);
        $player->setAllowFlight(false);
        $player->setFlying(false);
        $player->getInventory()->clearAll();
        $player->setGamemode(0);
    }

    /**
     * @param bool $restart
     */
    public function loadArena(bool $restart = false) {
        if(!$this->data["enabled"]) {
            $this->plugin->getLogger()->error("Can not load arena: Arena is not enabled!");
            return;
        }

        if(!$this->mapReset instanceof MapReset) {
            $this->mapReset = new MapReset($this);
        }

        if(!$restart) {
            $this->plugin->getServer()->getPluginManager()->registerEvents($this, $this->plugin);
        }



        else {
            $this->scheduler->reloadTimer();
            $this->level = $this->mapReset->loadMap($this->data["level"]);
        }

        if(!$this->level instanceof Level) {
            $level = $this->mapReset->loadMap($this->data["level"]);
            if(!$level instanceof Level) {
                $this->plugin->getLogger()->error("Arena level wasn't found. Try save level in setup mode.");
                $this->setup = true;
                return;
            }
            $this->level = $level;
        }
        $this->phase = static::PHASE_LOBBY;
        $this->players = [];
    }


    /**
     * @param bool $loadArena
     * @return bool $isEnabled
     */
    public function enable(bool $loadArena = true): bool {
        if(empty($this->data)) {
            return false;
        }
        if($this->data["level"] == null) {
            return false;
        }
        if(!$this->plugin->getServer()->isLevelGenerated($this->data["level"])) {
            return false;
        }
        if(!is_int($this->data["slots"])) {
            return false;
        }
        if(!is_array($this->data["spawns"])) {
            return false;
        }
        if(count($this->data["spawns"]) != $this->data["slots"]) {
            return false;
        }
        if(!is_array($this->data["joinsign"])) {
            return false;
        }
        if(count($this->data["joinsign"]) !== 2) {
            return false;
        }
        $this->data["enabled"] = true;
        $this->setup = false;
        if($loadArena) $this->loadArena();
        return true;
    }

    private function createBasicData() {
        $this->data = [
            "level" => null,
            "slots" => 12,
            "spawns" => [],
            "enabled" => false,
            "joinsign" => []
        ];
    }

    public function onArmorChange(PlayerInteractEvent $ev)
    {
        $player = $ev->getPlayer();

        $item = clone $ev->getItem();
        $check = ($ev->getAction() == PlayerInteractEvent::RIGHT_CLICK_BLOCK || $ev->getAction() == PlayerInteractEvent::RIGHT_CLICK_AIR);
        $isBlocked = (in_array($ev->getBlock()->getId(), [
            Block::ITEM_FRAME_BLOCK,
        ]));

        if ($check && !$isBlocked) {
            if ($ev->getItem() instanceof Armor) {
                $inventory = $player->getArmorInventory();
                $type = ArmorTypes::getType($item);
                $old = Item::get(Item::AIR, 0, 1); // just a placeholder
                if ($type !== ArmorTypes::TYPE_NULL) {
                    switch ($type) {
                        case ArmorTypes::TYPE_HELMET:
                            $old = clone $inventory->getHelmet();
                            $inventory->setHelmet($item);
                            break;
                        case ArmorTypes::TYPE_CHESTPLATE:
                            $old = clone $inventory->getChestplate();
                            $inventory->setChestplate($item);
                            break;
                        case ArmorTypes::TYPE_LEGGINGS:
                            $old = clone $inventory->getLeggings();
                            $inventory->setLeggings($item);
                            break;
                        case ArmorTypes::TYPE_BOOTS:
                            $old = clone $inventory->getBoots();
                            $inventory->setBoots($item);
                            break;
                    }
                    if (!$old->isNull()) {
                        $player->getInventory()->setItemInHand($old);
                    } else {
                        $player->getInventory()->setItemInHand(Item::get(Item::AIR, 0, 1));
                    }
                }
            }
        }
    }

    /*public function onItemHeld(PlayerItemHeldEvent $event){
        $player = $event->getPlayer();
        if($event->getItem()->getId() === ItemIds::COMPASS){
                $setNeedle = (bool)true;
                $nearPlayer = $this->calculateNearestPlayer($player);
                if($nearPlayer instanceof Player){
                    $myVector = $player->asVector3();
                    $nearVector = $nearPlayer->asVector3();
                    $message = \str_replace(['@pn', '@dn', '@tn', '@d'] ,[$nearPlayer->getName(), $nearPlayer->getDisplayName(), $nearPlayer->getNameTag(), (int) $myVector->distance($nearVector)], '§bNearest Player:§e @pn §8,§b Distance:§e @d §em');
                    $this->sendEachType($player, $message);
                }else{
                    $player->sendTip('§bNearest Player:§7 --- §8,§b Distance:§7 ---');
                }
            }
    }

    private function sendEachType(Player $player, string $message){
        $player->sendTip($message);
    }


    private function calculateNearestPlayer(Player $player) : ?Player{
        $closest = null;
        if($player instanceof Position){
            $lastSquare = -1;
            $onLevelPlayer = $player->getLevel()->getPlayers();
            unset($onLevelPlayer[array_search($player, $onLevelPlayer)]);
            foreach($onLevelPlayer as $p){
                $square = $player->distanceSquared($p);
                if($lastSquare === -1 || $lastSquare > $square){
                    $closest = $p;
                    $lastSquare = $square;
                }
            }
        }
        return $closest;
    }*/

    public function __destruct() {
        unset($this->scheduler);
    }
}
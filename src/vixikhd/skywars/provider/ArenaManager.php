<?php

namespace vixikhd\skywars\provider;

use pocketmine\Player;
use vixikhd\skywars\SkyWars;

class ArenaManager {
    public static $wins;
	protected $plugin;
	
	public function __construct(SkyWars $plugin){
		$this->plugin = $plugin;
	}

    /**
     * @param Player $player
    */
    public function setWins($player){
    	self::$wins = $this->plugin->getConfiguration("wins");
    	self::$wins->set($player->getName(), self::$wins->get($player->getName()) + 1);
    	self::$wins->save();
    }
    

    /**
     * @param Player $player
    */
    public function getWins($player){
    	self::$wins = $this->plugin->getConfiguration("wins");
    	return self::$wins->get($player->getName());
    }	
}
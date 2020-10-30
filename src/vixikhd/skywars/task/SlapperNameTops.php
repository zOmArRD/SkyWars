<?php

namespace vixikhd\skywars\task;

use pocketmine\entity\Entity;
use pocketmine\scheduler\Task;
use pocketmine\utils\{Config, TextFormat as TE};
use vixikhd\skywars\entity\EntityTops;
use vixikhd\skywars\SkyWars;

class SlapperNameTops extends Task {
	
	protected $plugin;
	
	public function __construct(SkyWars $plugin){
		$this->plugin = $plugin;
	}
	
	public function sendTag(Entity $entity){
		$kills = $this->plugin->getConfiguration("wins");
		$topw = array();
		$tope = $kills->getAll();
		foreach($tope as $key => $tp){
			array_push($topw, $tp);
		}
		natsort($topw);
		$grd = array_reverse($topw);
		$top1 = max($topw);
		$topv = array_search($top1, $tope);
		$top2 = array_search($grd[1], $tope);
		$top3 = array_search($grd[2], $tope);
		$top4 = array_search($grd[3], $tope);
		$top5 = array_search($grd[4], $tope);
		$top6 = array_search($grd[5], $tope);
		$top7 = array_search($grd[6], $tope);
		$top8 = array_search($grd[7], $tope);
		/*$top9 = array_search($grd[8], $tope);
		$top10 = array_search($grd[9], $tope);*/
		$rand = [TE::GOLD, TE::AQUA, TE::WHITE];
		$cc = $rand[array_rand($rand)];
		$entity->setNameTag(TE::BOLD."§l§b§k!!!§r§6§lSkyWars§3§k!!§r\n".$cc."§lLeaderBoard §7- §6WINS".TE::RESET."\n".TE::GOLD."#1- ".TE::WHITE.$topv.TE::GOLD." - ".TE::AQUA.$top1."\n".TE::GOLD."#2- ".TE::WHITE.$top2.TE::GOLD." - ".TE::AQUA.$grd[1]."\n".TE::GOLD."#3- ".TE::WHITE.$top3.TE::GOLD." - ".TE::AQUA.$grd[2]."\n".TE::GOLD."#4- ".TE::WHITE.$top4.TE::GOLD." - ".TE::AQUA.$grd[3]."\n".TE::GOLD."#5- ".TE::WHITE.$top5.TE::GOLD." - ".TE::AQUA.$grd[4]."\n".TE::GOLD."#6- ".TE::WHITE.$top6.TE::GOLD." - ".TE::AQUA.$grd[5]."\n".TE::GOLD."#7- ".TE::WHITE.$top7.TE::GOLD." - ".TE::AQUA.$grd[6]."\n".TE::GOLD."#8- ".TE::WHITE.$top8.TE::GOLD." - ".TE::AQUA.$grd[7]/*."\n".TE::GOLD."#9- ".TE::WHITE.$top9.TE::GOLD." - ".TE::AQUA.$grd[8]."\n".TE::GOLD."#10- ".TE::WHITE.$top10.TE::GOLD." - ".TE::AQUA.$grd[9]*/);
	}
	
	public function onRun(int $currentTick){
		foreach($this->plugin->getServer()->getLevels() as $level){
			foreach($level->getEntities() as $entity){
				if($entity instanceof EntityTops){
				    $this->sendTag($entity);
				}
			}
		}
	}
}
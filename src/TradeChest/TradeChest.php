<?php
namespace TradeChest;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\Server;
//use pocketmine\command\Command;
//use pocketmine\command\CommandSender;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\tile\Sign;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Config;

use pocketmine\level\Position;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\Item;
use pocketmine\tile\Tile;
use pocketmine\tile\Chest as TileChest;
use pocketmine\scheduler\Task;

class TradeChest extends PluginBase implements Listener{
	public $tap = [];
	public $blocks = [];

	const MAX_ITEM_COUNT = 65535;//内部にて使用している値であるため、可能であれば編集しないことをオススメします。

	public function onEnable(){
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->blocks = $this->read("blocks.json");
	}

	public function save(){
		$this->write("blocks.json",$this->blocks);
	}

	public function playerBlockTouch(PlayerInteractEvent $event){
		if($event->getBlock()->getID() == 68){//$event->getBlock()->getID() == 63
			$player = $event->getPlayer();
			$name = $player->getName();
			$sign1 = $player->getLevel()->getTile($event->getBlock());
			if(!($sign1 instanceof Sign)){
				return;
			}
			$sign = $sign1->getText();
			if($sign[0]=="§b[物々交換]"){
				//$data = explode(":",$sign[2]);
				$now = time();
				if(isset($this->tap[$name]) and $now - $this->tap[$name] < 2){
					$this->buyItem($player,$event->getBlock());
					unset($this->tap[$name]);
				}else{
					$this->tap[$name] = $now;
					$player->sendMessage("アイテムを物々交換するならもう一度タップ");
				}
			}else if($sign[0] == '[TSHOP]'){
				/*
					[TSHOP]
					1:0:64
					5:0:64
				*/
				$sides = [
					2 => Vector3::SIDE_SOUTH,
					3 => Vector3::SIDE_NORTH,
					4 => Vector3::SIDE_EAST,
					5 => Vector3::SIDE_WEST,
				];
				$block = $event->getBlock();
				if(!isset($sides[$block->getDamage()])){
					$player->sendMessage("§eタップした看板に張り付いているブロックはチェストではない可能性があるため、物々交換ショップを作成出来ません。");
					return;
				}
				$chestblock =  $block->getSide($sides[$block->getDamage()]);
				if($chestblock->getId() !== 54){
					$player->sendMessage("§eこの看板に張り付いているブロックはチェストでは無いため、物々交換ショップを作成することは出来ません。");
				}
				$data1 = explode(":",$sign[1]);
				$data2 = explode(":",$sign[2]);
				//preg_match("/[0-9]/", $sign[2])&&preg_match("/[0-9]/", $sign[3])
				if(preg_match("/[0-9]/", $data1[0])&&preg_match("/[0-9]/", $data2[0])){
					$beforeitem = $this->getitem($data1[0],$data1[1] ?? 0,$data1[2] ?? 64);
					$afteritem = $this->getitem($data2[0],$data2[1] ?? 0,$data2[2] ?? 64);
					if($beforeitem->getcount() > self::MAX_ITEM_COUNT||$afteritem->getcount() > self::MAX_ITEM_COUNT){
						$player->sendMessage(self::MAX_ITEM_COUNT."個以上の個数のアイテムを指定することは出来ません。");
					}
					$sign1->setText("§b[物々交換]",//0
					"§a交換前§r: ".$beforeitem->getName()."(".$beforeitem->getCount()."個)",//1
					"§b交換後§r: ".$afteritem->getName()."(".$afteritem->getCount()."個)",//2
					//"§e交換前: ".$data1[0].",".$data1[1]."(".$data1[2]."個)",//1
					//"§b交換後: ".$data2[0].",".$data2[1]."(".$data2[2]."個)",//2
					"§aオーナー§r: ".$player->getName());//3
					//$sign->saveNBT();
					$block = $event->getBlock();
					$this->blocks[self::getPositionHash($block->asVector3(),null,null,$block->getLevel()->getName())] = $this->encodemini($beforeitem,$afteritem,$player->getName());
					$player->sendMessage("§b[TSHOP]看板を活性化しました。");
					$this->save();
				}else{
					$player->sendMessage("不正な値があります。");
				}
			}
		}else if($event->getBlock()->getId() === 54){
			$sides = [
				Vector3::SIDE_WEST,
				Vector3::SIDE_EAST,
				Vector3::SIDE_NORTH,
				Vector3::SIDE_SOUTH,
			];
			$block = $event->getBlock();
			
			$player = $event->getPlayer();
			foreach($sides as $side){
				if($block->getSide($side)->getId() !== 68){
					continue;
				}
				$signblock = $block->getSide($side);
				$sign1 = $player->getLevel()->getTile($signblock->asVector3());
				if(!($sign1 instanceof Sign)){
					continue;
				}
				$sign = $sign1->getText();
				if($sign[0] === "§b[物々交換]"){
					if(($Rawdata = $this->getRawdata($signblock->asVector3(),$signblock->getLevel()->getName())) !== null){
						if($this->decodeowner($Rawdata) !== $player->getName()){
							$player->sendMessage("§d[物々交換]あなたはこのチェストを開く権限を持っておりません。");
							$event->setCancelled();
						}
					}
				}
			}
		}
	}

	public function buyItem($player,$block){
		if(($Rawdata = $this->getRawdata($block->asVector3(),$block->getLevel()->getName())) === null){
			$player->sendMessage("§e物々交換ショップの内部データは見つかりませんでした。");
			return;
		}
		$return = $this->decodemini($Rawdata);
		$BeforeItem = $return->getBeforeItem();
		if(!$player->getInventory()->contains($BeforeItem)){
			$player->sendMessage("§eインベントリに交換前のアイテム(".$BeforeItem->getId().":".$BeforeItem->getDamage()."(".$BeforeItem->getCount()."個))が存在しないため、取引することは出来ません。");
			return;
		}

		$sides = [
			2 => Vector3::SIDE_SOUTH,
			3 => Vector3::SIDE_NORTH,
			4 => Vector3::SIDE_EAST,
			5 => Vector3::SIDE_WEST,
		];

		if(!isset($sides[$block->getDamage()])){
			$player->sendMessage("§eタップした看板に張り付いているブロックはチェストではない可能性があるため、処理をすることは出来ません。");
			return;
		}
		$chestblock =  $block->getSide($sides[$block->getDamage()]);
		if($chestblock->getId() !== 54){
			$player->sendMessage("§eタップした看板に張り付いているブロックはチェストでは無いため、処理をすることは出来ません。");
			return;
		}

		$chest = $block->getLevel()->getTile($chestblock->asVector3());
		if(!$chest instanceof TileChest){
			$player->sendMessage("§eチェストの中身を取得出来ませんでした。");
			return;
		}
		
		$AfterItem = $return->getAfterItem();
		if(!$chest->getInventory()->contains($AfterItem)){
			$player->sendMessage("§b在庫切れの為、物々交換することは出来ません。");
			return;
		}

		if(!$chest->getInventory()->canAddItem($BeforeItem)){
			$player->sendMessage("§bチェストの中身はいっぱいの為、物々交換することは出来ません。");
			return;
		}

		$player->getInventory()->removeItem($BeforeItem);
		$chest->getInventory()->addItem($BeforeItem);
		
		$chest->getInventory()->removeItem($AfterItem);
		$player->getInventory()->addItem($AfterItem);
		$player->sendMessage("§a物々交換しました！");
	}

	public function Place(BlockPlaceEvent $event){
		if($event->getItem()->getID() === 54){
			$sides = [
				Vector3::SIDE_WEST,
				Vector3::SIDE_EAST,
				Vector3::SIDE_NORTH,
				Vector3::SIDE_SOUTH
			];
			$block = $event->getBlock();
			$player = $event->getPlayer();
			foreach($sides as $side){
				if($block->getSide($side)->getId() !== 54){
					continue;
				}
				$chestblock = $block->getSide($side);
				foreach($sides as $side){
					if($chestblock->getSide($side)->getId() !== 68){
						continue;
					}
					$signblock = $chestblock->getSide($side);
					$sign1 = $player->getLevel()->getTile($signblock->asVector3());
					if(!($sign1 instanceof Sign)){
						continue;
					}
					$sign = $sign1->getText();
					if($sign[0] === "§b[物々交換]"){
						$player->sendMessage("§d[物々交換]物々交換ショップの近くにチェストを設置することは出来ません。");
						$event->setCancelled();
					}
				}
			}
		}
	}


	public function onblockBreak(BlockBreakEvent $event){
		if($event->getBlock()->getId() === 54){
			$block = $event->getBlock();
			$sides = [
				Vector3::SIDE_WEST,
				Vector3::SIDE_EAST,
				Vector3::SIDE_NORTH,
				Vector3::SIDE_SOUTH
			];
			$player = $event->getPlayer();
			foreach($sides as $side){
				if($block->getSide($side)->getId() !== 68){
					continue;
				}
				$signblock = $block->getSide($side);
				$sign1 = $player->getLevel()->getTile($signblock->asVector3());
				if(!($sign1 instanceof Sign)){
					continue;
				}
				$sign = $sign1->getText();
				if($sign[0] === "§b[物々交換]"){
					if(($Rawdata = $this->getRawdata($signblock->asVector3(),$signblock->getLevel()->getName())) !== null){
						if($this->decodeowner($Rawdata) === $player->getName()||$player->isOP()){
							$player->sendMessage("§b物々交換ショップのデータを削除しました。");
							$this->DeleteRawdata($signblock->asVector3(),$signblock->getLevel()->getName());
						}else{
							$player->sendMessage("§d[物々交換]あなたはこのショップを壊す権限を持っていません。");
							$event->setCancelled();
						}
					}
				}
			}
		}else if($event->getBlock()->getId() === 68){
			$block = $event->getBlock();
			$player = $event->getPlayer();
			$sign1 = $player->getLevel()->getTile($block->asVector3());
			if(!($sign1 instanceof Sign)){
				return;
			}
			$sign = $sign1->getText();
			if($sign[0] === "§b[物々交換]"){
				if(($Rawdata = $this->getRawdata($block->asVector3(),$block->getLevel()->getName())) !== null){
					if($this->decodeowner($Rawdata) === $player->getName()||$player->isOP()){
						$player->sendMessage("§b物々交換ショップのデータを削除しました。");
						$this->DeleteRawdata($block->asVector3(),$block->getLevel()->getName());
					}else{
						$player->sendMessage("§d[物々交換]あなたはこのショップを壊す権限を持っていません。");
						$event->setCancelled();
					}
				}
			}
		}
	}

	public function getitem($id,$damage = 0,$count = 0){
		return Item::get((int) $id,(int) $damage,(int) $count);
	}

	public function encodemini(item $beforeitem,item $afteritem,String $owner): string{
		return base64_encode(chr($beforeitem->getId()).chr($beforeitem->getDamage()).$this->countencode($beforeitem->getcount()).chr($afteritem->getId()).chr($afteritem->getDamage()).$this->countencode($afteritem->getCount()).chr(strlen($owner)).$owner);
	}

	public function decodemini(string $data): decodeReturn{
		$data1 = base64_decode($data);
		$beforeitem = Item::get(ord($data1[0]), ord($data1[1]), $this->countdecode($data1[2],$data1[3]));
		$afteritem = Item::get(ord($data1[4]), ord($data1[5]), $this->countdecode($data1[6],$data1[7]));
		return (new decodeReturn($beforeitem,$afteritem,$this->decodeowner($data1)));
	}

	public function decodeowner(string $data): string{
		$data1 = base64_decode($data);
		return substr($data1,9,ord($data1[8]));
	}

	public function countencode(int $count): string{
		return chr($count >> 8).chr($count & 255);
	}

	public function countdecode(string $count,string $count1):  int{
		$test = ord($count);
		$test1 = ord($count1);
		return $test << 8 | $test1;
	}

	public function hasRawdata(Vector3 $vector3,string $levelname): bool{
		return isset($this->blocks[self::getPositionHash($vector3,null,null,$levelname)]);
	}

	public function getRawdata(Vector3 $vector3,string $levelname): ?string{
		if(!$this->hasRawdata($vector3,$levelname)){
			return null;
		}
		return $this->blocks[self::getPositionHash($vector3,null,null,$levelname)];
	}

	public function DeleteRawdata(Vector3 $vector3,string $levelname){
		if(!$this->hasRawdata($vector3,$levelname)){
			return false;
		}
		unset($this->blocks[self::getPositionHash($vector3,null,null,$levelname)]);
		$this->save();
		return true;
	}

	public static function getPositionHash($x,?int $y = null,?int $z = null,$level = null){
		$levelName = $level;//
		if($x instanceof Position){
			$levelName = $x->getName();
		}else if($level instanceof Level){
			$levelName = $level->getName();
		}else if($level === null){
			$levelName = null;
		}
		return self::getVector3Hash($x,$y,$z).":".$levelName;
	}

	public function read($filename){
		if(file_exists($this->getDataFolder().$filename)){
			$data = file_get_contents($this->getDataFolder().$filename);
			return json_decode($data,true);
		}else{
			return [];
		}
	}

	public function write($filename,$data){
		$json = json_encode($data,JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_BIGINT_AS_STRING);
		file_put_contents($this->getDataFolder().$filename,$json);
	}

	public static function getVector3Hash($x,?int $y = null,?int $z = null): string{
		if($x instanceof Vector3){
			return $x->x.",".$x->y.",".$x->z;
 		}
		return $x.",".$y.",".$z;
	}

}

class decodeReturn{
	public $beforeitem;
	public $afteritem;
	public $owner;

	public function __construct(item $beforeitem,item $afteritem,$owner){
		$this->beforeitem = $beforeitem;
		$this->afteritem = $afteritem;
		$this->owner = $owner;
	}

	public function getBeforeItem(): item{
		return $this->beforeitem;
	}

	public function getAfterItem(): item{
		return $this->afteritem;
	}

	public function getOwner(): item{
		return $this->owner;
	}
}
<?php

namespace Stock;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use pocketmine\command\PluginCommand;
use pocketmine\command\Command;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Utils;
use onebone\economyapi\EconomyAPI;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\network\protocol\AddItemEntityPacket;
use pocketmine\entity\Entity;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\block\Block;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\Player;
use Stock\task\StockpriceChangeTask;
use pocketmine\event\block\BlockBreakEvent;

class Stock extends PluginBase implements Listener
{

	private $m_version = 1, $db_version = 1, $plugin_version;
	private $messages, $stockDB, $config;
	private $newversion = false;
	private $createqueue = [], $removequeue = [], $eid = [];
	
	public function onEnable()
	{
		@mkdir ( $this->getDataFolder () );
		if($this->getServer()->getPluginManager()->getPlugin("EconomyAPI") == null){
			$this->getLogger()->error($this->get("cant-find-economyapi"));
			$this->getServer()->getPluginManager()->disablePlugin($this);
		}
		$this->NoticeVersionLicense();
		$this->LoadConfig();
		$this->messages = $this->Loadmessage();
		$this->stockDB = $this->Loadplugindata("stockDB.json");
		$this->Loadstockprice();
		$this->registerCommand($this->get("command-stock"), "Stock", "stock.command.allow", $this->get("command-description"), $this->get("command-help"));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		$this->getServer ()->getScheduler ()->scheduleRepeatingTask ( new StockpriceChangeTask( $this, $this ), $this->config["price-change-minute"] * 20 * 60 );
	}
	public function registerCommand($name, $fallback, $permission, $description = "", $usage = "") {
		$commandMap = $this->getServer ()->getCommandMap ();
		$command = new PluginCommand ( $name, $this );
		$command->setDescription ( $description );
		$command->setPermission ( $permission );
		$command->setUsage($usage);
		$commandMap->register ( $fallback, $command );
	}
	public function Loadstockprice()
	{
		if(!isset($this->stockDB["stock"]["price"])){
			$this->stockDB["stock"]["beforeprice"] = 0;
			$this->stockDB["stock"]["price"] = mt_rand($this->config["min-price"], $this->config["max-price"]);
			$this->getLogger()->info(str_replace("%money%", $this->stockDB["stock"]["price"], $this->get("set-stock-price")));
		}
	}
	public function onDisable()
	{
		$this->save("stockDB.json", $this->stockDB);
		$config = new Config($this->getDataFolder()."config.yml", Config::YAML);
		$config->setAll($this->config);
		$config->save();
	}
	public function LoadConfig()
	{
		$this->saveResource("config.yml");
		$this->config = (new Config($this->getDataFolder()."config.yml", Config::YAML))->getAll();
	}
	public function Loadmessage()
	{
		$this->saveResource("messages.yml");
		$this->UpdateMessage("messages.yml");
		return (new Config($this->getDataFolder()."messages.yml", Config::YAML))->getAll();
	}
	public function UpdateMessage($ymlfile)
	{
		$yml = (new Config($this->getDataFolder()."messages.yml", Config::YAML))->getAll();
		if(!isset($yml["m_version"])){
			$this->saveResource($ymlfile, true);
		}
		else if($yml["m_version"] < $this->m_version){
			$this->saveResource($ymlfile, true);
		}
	}
	/**
	 *
	 * @param string $dbname
	 * @param string $save true 로 설정시 resource 폴더에서 플러그인 데이터폴더로 불러옴.
	 */
	public function Loadplugindata($dbname, $save = false)
	{
		if($save == true){
			$this->saveResource($dbname);
			$this->UpdateDB($dbname);
		}
		return (new Config($this->getDataFolder().$dbname, Config::JSON))->getAll();
	}
	public function UpdateDB($dbname)
	{
		$db = (new Config($this->getDataFolder().$dbname, Config::JSON))->getAll();
		if(!isset($db["db_version"])){
			$this->saveResource($dbname, true);
		}
		else if($db["db_version"] < $this->db_version){
			$this->saveResource($dbname, true);
		}
	}
	public function save($dbname, $var)
	{
		$save = new Config($this->getDataFolder().$dbname, Config::JSON);
		$save->setAll($var);
		$save->save();
	}
	public function get($text)
	{
		return $this->messages[$this->messages["default-language"]."-".$text];
	}
	public function alert(CommandSender $sender, $message = "", $prefix = NULL)
	{
		if($prefix==NULL){
			$prefix = $this->get("default-prefix");
		}
		$sender->sendMessage(TextFormat::RED.$prefix." $message");
	}
	public function message(CommandSender $sender, $message = "", $prefix = NULL)
	{
		if($prefix==NULL){
			$prefix = $this->get("default-prefix");
		}
		$sender->sendMessage(TextFormat::DARK_AQUA.$prefix." $message");
	}
	public function UpdateAlert(PlayerJoinEvent $event)
	{
		$player = $event->getPlayer();
		if($player->isOp()){
			if($this->newversion){
				$this->alert($player, "Stock 플러그인의 새로운 버전이 있습니다. 새로운 버전으로 업데이트 해주세요!");
			}
		}
	}
	public function NoticeVersionLicense()
	{
		$this->getLogger()->alert("이 플러그인은 maru-EULA 라이센스를 사용합니다.");
		$this->getLogger()->alert("이 플러그인 사용시 라이센스에 동의하는것으로 간주합니다.");
		$this->getLogger()->alert("라이센스: https://github.com/wsj7178/PMMP-plugins/blob/master/LICENSE.md");
		$this->plugin_version = $this->getDescription()->getVersion();
		$version = json_decode(Utils::getURL("https://raw.githubusercontent.com/wsj7178/PMMP-plugins/master/version.json"), true);
		if($this->plugin_version < $version["Stock"]){
			$this->getLogger()->notice("플러그인의 새로운 버전이 존재합니다. 플러그인을 최신 버전으로 업데이트 해주세요!");
			$this->getLogger()->notice("현재버전: ".$this->plugin_version.", 최신버전: ".$version["Stock"]);
			$this->newversion = true;
		}
	}
	#============================================================================
	public function onCommand(CommandSender $sender, Command $command, $label, Array $args)
	{
		if (strtolower ( $command ) == $this->get ( "command-stock" )) {
			if (! isset ( $args [0] )) {
				if($sender->isOp()){
					$help = $this->get("command-ophelp");
				} else {
					$help = $this->get("command-help");
				}
				$this->alert($sender, $help);
				return true;
			}
			switch (strtolower ( $args [0] )) {
				case $this->get ("command-buy") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("buy-help"));
						return true;
					} else if (!is_numeric($args[1])){
						$this->alert($sender, $this->get("must-numeric"));
						return true;
					} else if ($args[1] <= 0){
						$this->alert($sender, $this->get("must-bigger-than-0"));
						return true;
					}
					$stockprice = $this->stockDB["stock"]["price"] * $args[1];
					if(EconomyAPI::getInstance()->myMoney($sender) < $stockprice){
						$this->alert($sender, $this->get("not-enough-money"));
						return true;
					}
					$this->stockDB["player"][strtolower($sender->getName())]["stockcount"] += $args[1];
					EconomyAPI::getInstance()->reduceMoney($sender, $stockprice);
					$this->message($sender, str_replace("%num%", $args[1], str_replace("%money%", $stockprice, $this->get("buy-success"))));
					break;
				case $this->get ("command-sell") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("sell-help"));
						return true;
					} else if (!is_numeric($args[1])){
						$this->alert($sender, $this->get("must-numeric"));
						return true;
					} else if($this->stockDB["player"][strtolower($sender->getName())]["stockcount"] < $args[1]){
						$this->alert($sender, $this->get("not-have-stock"));
						return true;
					} else if ($args[1] <= 0) {
						$this->alert($sender, $this->get("must-bigger-than-0"));
						return true;
					}
					$stockprice = $this->stockDB["stock"]["price"] * $args[1];
					$this->stockDB["player"][strtolower($sender->getName())]["stockcount"] -= $args[1];
					EconomyAPI::getInstance()->addMoney($sender, $stockprice);
					$this->message($sender, str_replace("%num%", $args[1], str_replace("%money%", $stockprice, $this->get("sell-success"))));
					break;
				case $this->get("command-info") :
					$count = $this->stockDB["player"][strtolower($sender->getName())]["stockcount"];
					$this->message($sender, str_replace("%price%", $this->getStockPrice() * $count, str_replace("%num%", $count, $this->get("stock-info"))));
					break;
				case $this->get("command-create") :
					if(!$sender->isOp()){
						$this->alert($sender, $this->get("not-have-permission"));
						return true;
					}
					$this->createqueue[$sender->getName()] = true;
					$this->message($sender, $this->get("touch-create"));
					break;
				case $this->get("command-remove") :
					if(!$sender->isOp()){
						$this->alert($sender, $this->get("not-have-permission"));
						return true;
					}
					$this->removequeue[$sender->getName()] = true;
					$this->message($sender, $this->get("touch-remove"));
					break;
				default :
					if($sender->isOp()){
						$help = $this->get("command-ophelp");
					} else {
						$help = $this->get("command-help");
					}
					$this->alert($sender, $help);
					break;
			}
		}
		return true;
	}
	public function getStockPrice()
	{
		return $this->stockDB["stock"]["price"];
	}
	public function CreateStockCase(Vector3 $pos, Level $level)
	{
		$pos->y++;
		foreach ($this->getServer()->getOnlinePlayers() as $player){
			$paper = $this->makePaperPacket($pos);
			$player->dataPacket($paper);
			$this->eid[$player->getName()]["case"][$this->PosToString($pos)] = $paper->eid;
		}
		$level->setBlock($pos, Block::get(Item::GLASS));
		$this->stockDB["stock"]["case"][$this->PosToString($pos)] = true;
	}
	public function makePaperPacket(Vector3 $pos)
	{
		$packet = new AddItemEntityPacket();
		$packet->eid = Entity::$entityCount++;
		$packet->item = Item::get(Item::PAPER);
		$packet->x = $pos->getX()+0.5;
		$packet->y = $pos->getY()+1;
		$packet->z = $pos->getZ()+0.5;
		return $packet;
	}
	public function removePacket(Vector3 $pos, Player $player)
	{
		$packet = new RemoveEntityPacket();
		$packet->eid = $this->eid[$player->getName()]["case"][$this->PosToString($pos)];
		$player->dataPacket($packet);
	}
	public function PosToString(Vector3 $pos)
	{
		return "{$pos->x}.{$pos->y}.{$pos->z}";
	}
	public function StringToPos($string)
	{
		$pos = explode(".", $string);
		return new Vector3($pos[0], $pos[1], $pos[2]);
	}
	public function onTouch(PlayerInteractEvent $event)
	{
		$player = $event->getPlayer();
		$block = $event->getBlock();
		$x = $block->getX();
		$y = $block->getY();
		$z = $block->getZ();
		$pos = new Vector3($x, $y, $z);
		if(isset($this->createqueue[$player->getName()])){
			$this->CreateStockCase($pos, $event->getBlock()->getLevel());
			$this->message($player, $this->get("success-create"));
			unset($this->createqueue[$player->getName()]);
			return true;
		} else if (isset($this->removequeue[$player->getName()])) {
			foreach ($this->stockDB["stock"]["case"] as $posstring=>$boolean){
				if($this->PosToString($pos) == $posstring){
					unset($this->stockDB["stock"]["case"][$posstring]);
					foreach ($this->getServer()->getOnlinePlayers() as $target){
					$this->removePacket($pos, $target);
				}
				$this->message($player, $this->get("success-remove"));
				unset($this->removequeue[$player->getName()]);
				break;
				}
			}
		}
		if (isset($this->stockDB["stock"]["case"])){
			foreach($this->stockDB["stock"]["case"] as $posstring=>$boolean){
				if($posstring == $this->PosToString($pos)){
					$this->message($player, str_replace("%money%", $this->stockDB["stock"]["beforeprice"], $this->get("stock-information-1")));
					$this->message($player, str_replace("%money%", $this->stockDB["stock"]["price"], $this->get("stock-information-2")));
				}
			}
		}
	}
	public function stockpriceChange()
	{
		$min = $this->config["min-price"];
		$max = $this->config["max-price"];
		if($this->getStockPrice() < $min){
			if(mt_rand(1, 100) <= 60){
				if(mt_rand(1, 100) <= 30){
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney = mt_rand((int)($min / 10), (int)($min / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] += $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("increase-price")))));
			} else {
				if (mt_rand(1, 100) <= 20){
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney = mt_rand((int)($min / 10), (int)($min / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] -= $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("decrease-price")))));
			}
		} else if ($this->getStockPrice() > $max) {
			if(mt_rand(1, 100) <= 40){
				if(mt_rand(1, 100) <= 20){
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney = mt_rand((int)($min / 10), (int)($min / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] += $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("increase-price")))));
			} else {
				if (mt_rand(1, 100) <= 30){
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney = mt_rand((int)($min / 10), (int)($min / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] -= $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("decrease-price")))));
			}
		} else {
			$probability = mt_rand(0, 1);
			if($probability){
				$probability = mt_rand(1, 100);
				if($probability <= 20) {
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney = mt_rand((int)($min / 10), (int)($min / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] += $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("increase-price")))));
			} else {
				$probability = mt_rand(1, 100);
				if($probability <= 20){
					$changemoney = mt_rand((int)($max / 10), (int)($max / 10 * 3));
				} else {
					$changemoney =  mt_rand((int)($min / 10), (int)($max / 10 * 3));
				}
				$this->stockDB["stock"]["beforeprice"] = $this->stockDB["stock"]["price"];
				$this->stockDB["stock"]["price"] -= $changemoney;
				$this->getServer()->broadcastMessage(TextFormat::BLUE.$this->get("default-prefix")." ".str_replace("%before%", $this->stockDB["stock"]["beforeprice"], str_replace("%after%", $this->stockDB["stock"]["price"], str_replace("%money%", $changemoney, $this->get("decrease-price")))));
			}
		}
		if ($this->stockDB["stock"]["price"] < 0) {
			$this->getServer()->broadcastMessage(TextFormat::RED.$this->get("default-prefix")." ".$this->get("stock-bankruptcy"));
			unset($this->stockDB["player"]);
			$this->stockDB["stock"]["beforeprice"] = 0;
			$this->stockDB["stock"]["price"] = mt_rand($this->config["min-price"], $this->config["max-price"]);
			return true;
		}
	}
	public function onJoin(PlayerJoinEvent $event)
	{
		$player = $event->getPlayer();
		if (!isset($this->stockDB["player"][strtolower($player->getName())]["stockcount"])){
			$this->stockDB["player"][strtolower($player->getName())]["stockcount"] = 0;
		}
		if (isset($this->stockDB["stock"]["case"])){
			foreach ($this->stockDB["stock"]["case"] as $posstring=>$boolean){
				$pos = $this->StringToPos($posstring);
				$paper = $this->makePaperPacket($pos);
				$player->dataPacket($paper);
				$this->eid[$player->getName()]["case"][$this->PosToString($pos)] = $paper->eid;
			}
		}
	}
	public function onBreak(BlockBreakEvent $event)
	{
		$block = $event->getBlock();
		$x = $block->getX();
		$y = $block->getY();
		$z = $block->getZ();
		$pos = "{$x}.{$y}.{$z}";
		if(isset($this->stockDB["stock"]["case"])){
			foreach ($this->stockDB["stock"]["case"] as $posstring=>$b){
				if($pos == $posstring){
					$event->setCancelled();
				}
			}
		}
	}
}

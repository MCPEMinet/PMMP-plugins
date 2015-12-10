<?php

namespace Itemcase;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use Itemcase\task\ExampleTask;
use pocketmine\command\PluginCommand;
use pocketmine\command\Command;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\utils\Utils;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\network\protocol\AddItemEntityPacket;
use pocketmine\entity\Entity;
use pocketmine\entity\Item;
use pocketmine\math\Vector3;
use pocketmine\block\Block;
use pocketmine\level\Level;

class Itemcase extends PluginBase implements Listener
{

	private $m_version = 1, $db_version = 1, $plugin_version;
	private $messages, $pluginDB;
	private $newversion = false;
	private $createqueue = [];
	
	public function onEnable()
	{
		@mkdir ( $this->getDataFolder () );
		$this->getLogger()->alert("이 플러그인은 maru-EULA 라이센스를 사용합니다.");
		$this->getLogger()->alert("이 플러그인 사용시 라이센스에 동의하는것으로 간주합니다.");
		$this->getLogger()->alert("라이센스: https://github.com/wsj7178/PMMP-plugins/blob/master/LICENSE.md");
		$this->plugin_version = $this->getDescription()->getVersion();
		$version = json_decode(Utils::getURL("https://raw.githubusercontent.com/wsj7178/PMMP-plugins/master/version.json"), true);
		if($this->plugin_version < $version["Itemcase"]){
			$this->getLogger()->notice("플러그인의 새로운 버전이 존재합니다. 플러그인을 최신 버전으로 업데이트 해주세요!");
			$this->getLogger()->notice("현재버전: ".$this->plugin_version.", 최신버전: ".$version["Itemcase"]);
			$this->newversion = true;
		}
		$this->messages = $this->Loadmessage();
		$this->pluginDB = $this->Loadplugindata("pluginDB.json");
		$this->registerCommand($this->get("command"), "Itemcase", "itemcase.command.allow", $this->get("command-description"), $this->get("command-help"));
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		//$this->getServer ()->getScheduler ()->scheduleRepeatingTask ( new ExampleTask( $this ), 12000 );
	}
	public function registerCommand($name, $fallback, $permission, $description = "", $usage = "") {
		$commandMap = $this->getServer ()->getCommandMap ();
		$command = new PluginCommand ( $name, $this );
		$command->setDescription ( $description );
		$command->setPermission ( $permission );
		$command->setUsage ( $usage );
		$commandMap->register ( $fallback, $command );
	}
	public function onDisable()
	{
		$this->save("pluginDB.json", $this->pluginDB);
	}
	public function Loadmessage()
	{
		$this->saveResource("messages.yml");
		$this->Updatemesage("messages.yml");
		return (new Config($this->getDataFolder()."messages.yml", Config::YAML))->getAll();
	}
	public function Updatemesage($ymlfile)
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
	public function alert(CommandSender $sender, $message, $prefix = NULL)
	{
		if($prefix==NULL){
			$prefix = $this->get("default-prefix");
		}
		$sender->sendMessage(TextFormat::RED.$prefix." $message");
	}
	public function message(CommandSender $sender, $message, $prefix = NULL)
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
				$this->alert($player, "Itemcase 플러그인의 새로운 버전이 있습니다. 새로운 버전으로 업데이트 해주세요!");
			}
		}
	}
	#============================================================================
	public function onCommand(CommandSender $sender, Command $command, $label, Array $args)
	{
		if (strtolower ( $command ) == $this->get ( "command" )) { 
			if (! isset ( $args [0] )) {
				$this->alert($sender, $this->get("command-help"));
				return true;
			}
			switch (strtolower ( $args [0] )) {
				case $this->get ("command-add") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("add-help"));
						return true;
					}
					if(!is_numeric($args[1])){
						$this->alert($sender, $this->get("item-not-number"));
						return true;
					}
					$this->message($sender, $this->get("touch-block"));
					$this->createqueue[$sender->getName()] = $args[1];
					break;
				case $this->get ("command-delete") :
					
					break;
				default :
					// TODO - 잘못된 명령어 입력시 도움말 표시
					break;
			}
		}
		return true;
	}
	public function onTouch(PlayerInteractEvent $event)
	{
		$player = $event->getPlayer()->getName();
		if(isset($this->createqueue[$player])){
			$item = \pocketmine\item\Item::get($this->createqueue[$player]);
			$pos = new Vector3($event->getBlock()->getX(), $event->getBlock()->getY()+1, $event->getBlock()->getZ());
			$this->AddItemCase($item, $pos, $event->getBlock()->getLevel());
			unset($this->createqueue[$player]);
			$player = $event->getPlayer();
			$this->message($player, $this->get("create-success"));
	
		}
	}
	public function AddItemCase(\pocketmine\item\Item $item, Vector3 $pos, Level $level)
	{
		$packet = new AddItemEntityPacket();
		$packet->eid = Entity::$entityCount++;
		$packet->item = $item;
		$packet->x = $pos->getX()+0.5;
		$packet->y = $pos->getY();
		$packet->z = $pos->getZ()+0.5;
		$players = $this->getServer()->getOnlinePlayers();
		foreach ($players as $player){
			$player->dataPacket($packet);
		}
		$level->setBlock($pos, Block::get(\pocketmine\item\Item::GLASS));
	}
}

<?php

namespace UUIDBan;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat;
use UUIDBan\task\ExampleTask;
use pocketmine\command\PluginCommand;
use pocketmine\command\Command;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\Player;
use pocketmine\utils\Utils;

class UUIDBan extends PluginBase implements Listener
{

	private $m_version = 2, $db_version = 1, $plugin_version;
	private $messages, $uuidDB, $banlist, $newversion = false;
	private static $instance;

	public function onLoad()
	{
		self::$instance = $this;
	}
	public function onEnable()
	{
		@mkdir ( $this->getDataFolder () );
		$this->getLogger()->alert("이 플러그인은 maru-EULA 라이센스를 사용합니다.");
		$this->getLogger()->alert("이 플러그인 사용시 라이센스에 동의하는것으로 간주합니다.");
		$this->getLogger()->alert("라이센스: https://github.com/wsj7178/PMMP-plugins/blob/master/LICENSE.md");
		$this->versioncheck();
		$this->messages = $this->Loadmessage();
		$this->uuidDB = $this->Loadplugindata("uuidDB.json");
		$this->banlist = $this->Loadplugindata("banlist.json");
		$this->registerCommand($this->get("command-uuidban"), "UUIDBan", "uuidban.command.allow", $this->get("uuidban-description"), $this->get("uuidban-help"));
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
	public static function getInstance()
	{
		return self::$instance;
	}
	public function versioncheck()
	{
		$this->plugin_version = $this->getDescription()->getVersion();
		$version = json_decode(Utils::getURL("https://raw.githubusercontent.com/wsj7178/PMMP-plugins/master/version.json"), true);
		if($this->plugin_version < $version["UUIDBan"]){
			$this->getLogger()->notice("플러그인의 새로운 버전이 존재합니다. 플러그인을 최신 버전으로 업데이트 해주세요!");
			$this->getLogger()->notice("현재버전: {$this->plugin_version}, 최신버전: ".$version["UUIDBan"]);
			$this->newversion = true;
		}
	}
	public function onDisable()
	{
		
		$this->save("uuidDB.json", $this->uuidDB);
		$this->save("banlist.json", $this->banlist);
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
	#============================================================================
	public function onCommand(CommandSender $sender, Command $command, $label, Array $args)
	{
		if (strtolower ( $command ) == $this->get ( "command-uuidban" )) {
			if (! isset ( $args [0] )) {
				$this->alert($sender, $this->get("uuidban-help"));
				return true;
			}
			switch (strtolower ( $args [0] )) {
				case $this->get ("command-add") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("add-help"));
						return true;
					}
					$target = strtolower($args[1]);
					if(!isset($args[2])){
						$reason = $this->get("cant-find-reason");
					}else{
						array_shift($args);
						array_shift($args);
						$reason = implode(" ", $args);
					}
					if($this->AddBan($target, $reason, $sender->getName())){
						$this->message($sender, $this->get("ban-success"));
					} else {
						$this->message($sender, $this->get("cant-find-uuid"));
					}
					break;
				case $this->get ("command-delete") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("delete-help"));
						return true;
					}
					$target = strtolower($args[1]);
					if($this->DeleteBan($target)){
						$this->message($sender, $this->get("unban-success"));
					} else {
						$this->alert($sender, $this->get("not-banned"));
					}
					break;
				case $this->get("command-list") :
					if(!isset($this->banlist)){
						$this->alert($sender, $this->get("cant-find-list"));
						return true;
					}
					$list="목록 :\n";
					$count = 1;
					foreach($this->banlist as $player=>$uuid){
						if($player == "other") continue;
						$list .= $player."   ";
						$count++;
						if($count % 7 == 0){
							$list .= "\n";
						}
					}
					$sender->sendMessage($list);
					break;
				case $this->get("command-reset") :
					if(!isset($this->banlist)){
						$this->alert($sender, $this->get("cant-find-list"));
						return true;
					}
					unset($this->banlist);
					$this->message($sender, $this->get("unban-all"));
					break;
				case $this->get("command-find") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("find-help"));
						return true;
					}
					$target = strtolower($args[1]);
					if(!isset($this->uuidDB[$target])){
						$this->alert($sender, $this->get("cant-find-uuid"));
						return true;
					}
					$uuid = $this->uuidDB[$target];
					$this->message($sender, "밴기록 ");
					foreach($this->banlist as $player=>$uuidlist){
						if($uuid == $uuidlist)
							$this->message($sender, "{$target}님은 {$player} 계정에서 밴당하셨습니다.");
					}
					break;
				case $this->get("command-reason") :
					if(!isset($args[1])){
						$this->alert($sender, $this->get("reason-help"));
						return true;
					}
					$target = strtolower($args[1]);
					if(!isset($this->banlist[$target])){
						$this->alert($sender, $this->get("not-banned"));
						return true;
					}
					if(!isset($this->banlist["other"][$target])){
						$reason = $this->get("cant-find-reason");
						$bannedby = "OP";
					}else{
						$reason = $this->banlist["other"][$target]["reason"];
						$bannedby = $this->banlist["other"][$target]["bannedby"];
					}
					$this->message($sender, str_replace("%player%", $target, str_replace("%reason%", $reason, str_replace("%op%", $bannedby, $this->get("show-reason")))));
					break;
				default :
					$this->alert($sender, $this->get("uuidban-help"));
					break;
			}
		}
		return true;
	}
	/**
	 * 
	 * @param Player|string $player
	 * @param string $reason
	 * @param string $banner
	 * 
	 * @return boolean 밴 성공시 true, 실패시 false 반환
	 */
	public function AddBan($player, $reason = "사유 미입력", $banner = "OP")
	{
		if($player instanceof Player){
			$player = $player->getName();
		}
		$player = strtolower($player);
		if(!isset($this->uuidDB[$player])){
			return false;
		}
		$this->banlist[$player] = $this->uuidDB[$player];
		$this->banlist["other"][$player]["reason"] = $reason;
		$this->banlist["other"][$player]["bannedby"] = $banner;
		$player = $this->getServer()->getPlayer($player);
		if($player instanceof Player){
			$player->kick($this->get("you-are-banned"));
		}
		return true;
	}
	/**
	 * 
	 * @param string $player
	 * 
	 * @return boolean 밴해제 성공시 true, 실패시 false 반환
	 */
	public function DeleteBan($player)
	{
		$player = strtolower($player);
		if(!isset($this->banlist[$player])){
			return false;
		}
		unset($this->banlist[$player]);
		if(isset($this->banlist["other"][$player])){
			unset($this->banlist["other"][$player]);
		}
		return true;
	}
	public function onLogin(PlayerPreLoginEvent $event)
	{
		$player = $event->getPlayer();
		$uuid = $player->getClientId();
		if(!isset($this->banlist)){
			$this->alert($sender, $this->get("cant-find-list"));
			return true;
		}
		foreach($this->banlist as $uuidlist){
			if($uuid == $uuidlist){
				$event->setKickMessage($this->get("you-are-banned"));
				$event->setCancelled();
				return true;
			}
		}
		$this->uuidDB[strtolower($player->getName())] = $uuid;
	}
	public function onJoin(PlayerJoinEvent $event)
	{
		$player = $event->getPlayer();
		if($player->isOp()){
			if($this->newversion){
				$this->alert($player, "UUIDBan 플러그인의 새로운 버전이 있습니다. 새로운 버전으로 업데이트 해주세요!");
			}
		}
	}
}

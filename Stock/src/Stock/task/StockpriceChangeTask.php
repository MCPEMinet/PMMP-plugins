<?php
//
namespace Stock\task;

use pocketmine\scheduler\PluginTask;
use pocketmine\plugin\Plugin;
use Stock\Stock;

class StockpriceChangeTask extends PluginTask {
	protected $owner, $plugin;
	public function __construct(Plugin $owner, Stock $plugin) {
		parent::__construct ( $owner );
		$this->plugin = $plugin;
	}
	public function onRun($currentTick) {
		$this->plugin->stockpriceChange();
	}
}
?>

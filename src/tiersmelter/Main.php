<?php

declare(strict_types=1);

namespace tiersmelter;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\tile\BlastFurnace as BlastFurnaceTile;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\inventory\FurnaceSmeltEvent;
use pocketmine\world\Position;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\particle\HugeExplodeParticle;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\world\sound\NoteSound;
use pocketmine\world\sound\NoteInstrument;

class Main extends PluginBase implements Listener {

    private Config $tiers;
    private Config $furnaces;
    private array $smeltingQueue = [];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        @mkdir($this->getDataFolder());

        $this->tiers = new Config($this->getDataFolder() . "tiers.yml", Config::YAML);
        $this->furnaces = new Config($this->getDataFolder() . "furnaces.yml", Config::YAML);

        $this->getLogger()->info(TextFormat::GREEN . "TierSmelter has been enabled!");

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->tickSmeltingQueue();
        }), 1);
    }

    protected function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . "TierSmelter has been disabled!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (strtolower($command->getName()) !== "tiersmelter") {
            return false;
        }
        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
            return true;
        }
        if (count($args) < 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: /tiersmelter <create|delete|list|set>");
            return true;
        }
        switch (strtolower($args[0])) {
            case "create":
                if (count($args) < 3) {
                    $sender->sendMessage(TextFormat::YELLOW . "Usage: /tiersmelter create <tierName> <smeltTimeInSeconds>");
                    return true;
                }
                $tierName = strtolower($args[1]);
                $smeltTime = (float)$args[2];
                if ($smeltTime <= 0) {
                    $sender->sendMessage(TextFormat::RED . "Smelt time must be a positive number.");
                    return true;
                }
                $this->tiers->set($tierName, $smeltTime);
                $this->tiers->save();
                $sender->sendMessage(TextFormat::GREEN . "Successfully created tier '$tierName' with smelt time $smeltTime seconds.");
                break;
            case "delete":
                if (count($args) < 2) {
                    $sender->sendMessage(TextFormat::YELLOW . "Usage: /tiersmelter delete <tierName>");
                    return true;
                }
                $tierName = strtolower($args[1]);
                if (!$this->tiers->exists($tierName)) {
                    $sender->sendMessage(TextFormat::RED . "Tier '$tierName' does not exist.");
                    return true;
                }
                $this->tiers->remove($tierName);
                $this->tiers->save();
                $sender->sendMessage(TextFormat::GREEN . "Successfully deleted tier '$tierName'.");
                break;
            case "list":
                $allTiers = $this->tiers->getAll();
                if (empty($allTiers)) {
                    $sender->sendMessage(TextFormat::YELLOW . "No tiers created yet. Use '/tiersmelter create <name> <time>'.");
                    return true;
                }
                $sender->sendMessage(TextFormat::GOLD . "--- TierSmelter Tiers ---");
                foreach ($allTiers as $name => $time) {
                    $sender->sendMessage(TextFormat::YELLOW . "$name: " . TextFormat::WHITE . "$time seconds");
                }
                break;
            case "set":
                if (count($args) < 2) {
                    $sender->sendMessage(TextFormat::YELLOW . "Usage: /tiersmelter set <tierName>");
                    return true;
                }
                $tierName = strtolower($args[1]);
                if (!$this->tiers->exists($tierName)) {
                    $sender->sendMessage(TextFormat::RED . "Tier '$tierName' does not exist.");
                    return true;
                }
                $block = $sender->getTargetBlock(5);
                if ($block === null || $block->getTypeId() !== BlockTypeIds::BLAST_FURNACE) {
                    $sender->sendMessage(TextFormat::RED . "You must be looking at a Blast Furnace to set a tier.");
                    return true;
                }
                $pos = $block->getPosition();
                $furnaceKey = $this->getFurnaceKey($pos);
                $this->furnaces->set($furnaceKey, $tierName);
                $this->furnaces->save();
                $sender->sendMessage(TextFormat::GREEN . "This Blast Furnace has been set to the '$tierName' tier.");
                break;
            default:
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /tiersmelter <create|delete|list|set>");
                break;
        }
        return true;
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $block = $event->getBlock();
        if ($block->getTypeId() === BlockTypeIds::BLAST_FURNACE) {
            $furnaceKey = $this->getFurnaceKey($block->getPosition());
            if ($this->furnaces->exists($furnaceKey)) {
                $this->furnaces->remove($furnaceKey);
                $this->furnaces->save();
                $event->getPlayer()->sendMessage(TextFormat::AQUA . "Tiered Blast Furnace has been removed.");
            }
        }
    }

    public function onFurnaceSmelt(FurnaceSmeltEvent $event): void {
        $block = $event->getBlock();
        if ($block->getTypeId() !== BlockTypeIds::BLAST_FURNACE) {
            return;
        }
        $pos = $block->getPosition();
        $furnaceKey = $this->getFurnaceKey($pos);
        if (!$this->furnaces->exists($furnaceKey)) {
            return;
        }

        $tierName = $this->furnaces->get($furnaceKey);
        if (!$this->tiers->exists($tierName)) {
            return;
        }

        $tile = $pos->getWorld()->getTile($pos);
        if (!$tile instanceof BlastFurnaceTile) {
            return;
        }
        $inventory = $tile->getInventory();
        $input = $inventory->getItem(0);
        $result = $event->getResult();

        $smeltTimeSeconds = (float)$this->tiers->get($tierName);
        $smeltTimeTicks = (int)ceil($smeltTimeSeconds * 20);

        if (!isset($this->smeltingQueue[$furnaceKey])) {
            // spawn explosion particle at start for a dramatic kickstart ig? hehe
            $world = $pos->getWorld();
            $center = $pos->add(0.5, 1.0, 0.5);
            $world->addParticle($center, new HugeExplodeParticle());
            $world->addSound($center, new ExplodeSound());

            $this->smeltingQueue[$furnaceKey] = [
                "position" => $pos,
                "result" => $result,
                "inputName" => $input->getName(),
                "smeltTime" => $smeltTimeTicks,
                "ticksProgress" => 0
            ];
        }
    }

    private function tickSmeltingQueue(): void {
        foreach ($this->smeltingQueue as $key => &$data) {
            $pos = $data["position"];
            $tile = $pos->getWorld()->getTile($pos);
            if (!$tile instanceof BlastFurnaceTile) {
                unset($this->smeltingQueue[$key]);
                continue;
            }

            $inventory = $tile->getInventory();
            $input = $inventory->getItem(0);
            $fuel = $inventory->getItem(1);
            $output = $inventory->getItem(2);
            $result = $data["result"];
            $smeltTime = $data["smeltTime"];

            // infinite still in the works
            if ($fuel->isNull() || $fuel->getCount() === 0) {
                continue;
            }

            if ($input->isNull() || $input->getName() !== $data["inputName"]) {
                unset($this->smeltingQueue[$key]);
                continue;
            }

            $freeSpace = $result->getMaxStackSize() - ($output->isNull() ? 0 : $output->getCount());
            if ($freeSpace <= 0) {
                continue;
            }

            $data["ticksProgress"]++;

            if ($data["ticksProgress"] >= $smeltTime) {
                if ($input->getCount() <= 0) {
                    unset($this->smeltingQueue[$key]);
                    continue;
                }

                // remove input
                $input->setCount($input->getCount() - 1);
                $inventory->setItem(0, $input);

                // add output
                if ($output->isNull()) {
                    $out = clone $result;
                    $out->setCount(1);
                    $inventory->setItem(2, $out);
                } elseif ($output->getTypeId() === $result->getTypeId()) {
                    $inventory->setItem(2, $output->setCount($output->getCount() + 1));
                }

                $pos->getWorld()->addSound($pos, new NoteSound(NoteInstrument::PLING, 1));

                $data["ticksProgress"] = 0;

                if ($input->getCount() === 0) {
                    unset($this->smeltingQueue[$key]);
                }
            }
        }
    }

    private function getFurnaceKey(Position $position): string {
        return $position->getWorld()->getFolderName() . ":" . $position->getFloorX() . ":" . $position->getFloorY() . ":" . $position->getFloorZ();
    }
}

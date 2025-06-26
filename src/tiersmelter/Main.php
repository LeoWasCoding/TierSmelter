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
use jojoe77777\FormAPI\SimpleForm;
use jojoe77777\FormAPI\CustomForm;

class Main extends PluginBase implements Listener {

    private Config $tiers;
    private Config $furnaces;

    /**
     * @var array<string, array{position: Position, result: \pocketmine\item\Item, inputName: string, smeltTime: int, ticksProgress: int}>
     */
    private array $smeltingQueue = [];

    protected function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        @mkdir($this->getDataFolder());

        $this->tiers = new Config($this->getDataFolder() . "tiers.yml", Config::YAML);
        $this->furnaces = new Config($this->getDataFolder() . "furnaces.yml", Config::YAML);

        $this->getLogger()->info(TextFormat::GREEN . "TierSmelter has been enabled!");

        // Schedule per-tick smelting queue processing
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
            $this->tickSmeltingQueue();
        }), 1);
    }

    protected function onDisable(): void {
        $this->getLogger()->info(TextFormat::RED . "TierSmelter has been disabled!");
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (strtolower($command->getName()) !== "tiersmelter") return false;

        if (!$sender instanceof Player) {
            $sender->sendMessage(TextFormat::RED . "This command can only be used in-game.");
            return true;
        }

        if (count($args) < 1) {
            $this->openMainForm($sender);
            return true;
        }
        if (isset($args[0]) && strtolower($args[0]) === "tutorial") {
            $this->showTutorialStep($sender, 1);
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

    private function showTutorialStep(Player $player, int $step): void {
        $form = new \jojoe77777\FormAPI\SimpleForm(function (Player $player, ?int $data) use ($step): void {
            if ($data === null) return; // Form closed or exited

            $nextStep = null;
            if ($data === 0) { // Next
                $nextStep = $step + 1;
            } elseif ($data === 1) { // Back
                $nextStep = $step - 1;
            }

            // Clamp step between 1 and 6
            if ($nextStep !== null && $nextStep >= 1 && $nextStep <= 6) {
                $this->showTutorialStep($player, $nextStep);
            }
        });

        switch ($step) {
            case 1:
                $form->setTitle("§l§6TierSmelter Tutorial §7(1/6)");
                $form->setContent(
                    "Welcome to TierSmelter!\n\n" .
                    "This plugin allows you to create custom smelting tiers for Blast Furnaces, " .
                    "speeding up or slowing down smelting times.\n\n" .
                    "Use this tutorial to learn how to manage tiers and assign them."
                );
                $form->addButton("Next ▶");
                break;

            case 2:
                $form->setTitle("§l§6Create a Tier §7(2/6)");
                $form->setContent(
                    "To create a new tier:\n\n" .
                    "1. Use the command:\n" .
                    "   /tiersmelter create <tierName> <smeltTimeInSeconds>\n\n" .
                    "Example:\n" .
                    "   /tiersmelter create TierI 5\n\n" .
                    "This creates a tier called 'TierI' with a smelting time of 5 seconds. (which is default speed)"
                );
                $form->addButton("Next ▶");
                $form->addButton("◀ Back");
                break;

            case 3:
                $form->setTitle("§l§6Assign Tier to Furnace §7(3/6)");
                $form->setContent(
                    "Assign a tier to a Blast Furnace:\n\n" .
                    "1. Look at the Blast Furnace block.\n" .
                    "2. Run the command:\n" .
                    "   /tiersmelter set <tierName>\n\n" .
                    "The furnace will now smelt items with the tier's speed."
                );
                $form->addButton("Next ▶");
                $form->addButton("◀ Back");
                break;

            case 4:
                $form->setTitle("§l§6Delete a Tier §7(4/6)");
                $form->setContent(
                    "To delete an existing tier:\n\n" .
                    "Run:\n" .
                    "   /tiersmelter delete <tierName>\n\n" .
                    "Be careful, this cannot be undone!"
                );
                $form->addButton("Next ▶");
                $form->addButton("◀ Back");
                break;

            case 5:
                $form->setTitle("§l§6List All Tiers §7(5/6)");
                $form->setContent(
                    "To see all available tiers, run:\n\n" .
                    "   /tiersmelter list\n\n" .
                    "This lists each tier name and its smelting time."
                );
                $form->addButton("Next ▶");
                $form->addButton("◀ Back");
                break;

            case 6:
                $form->setTitle("§l§6Tutorial Complete! §7(6/6)");
                $form->setContent(
                    "You've completed the TierSmelter tutorial!\n\n" .
                    "You can now create, assign, delete, and list smelting tiers with ease.\n\n" .
                    "Use /tiersmelter anytime for the main menu."
                );
                $form->addButton("◀ Back");
                $form->addButton("Close");
                break;
        }

        $player->sendForm($form);
    }

    private function openMainForm(Player $player): void {
        $form = new SimpleForm(function (Player $player, ?int $data): void {
            if ($data === null) return;

            switch ($data) {
                case 0:
                    $this->openCreateTierForm($player);
                    break;
                case 1:
                    $this->openDeleteTierForm($player);
                    break;
                case 2:
                    $this->listTiersToPlayer($player);
                    break;
                case 3:
                    $this->setTierToFurnace($player);
                    break;
                case 4:
                    $this->showTutorialStep($player, 1);
                    break;
            }
        });

        $form->setTitle("§lTierSmelter Menu");
        $form->setContent("§7§lChoose an action:");
        $form->addButton("§a§lCreate Tier");
        $form->addButton("§c§lDelete Tier");
        $form->addButton("§a§lList Tiers");
        $form->addButton("§a§lSet Tier to Furnace");
        $form->addButton("§6§lTutorial");

        $player->sendForm($form);
    }

    private function openCreateTierForm(Player $player): void {
        $form = new CustomForm(function (Player $player, ?array $data): void {
            if ($data === null) return;

            $tierName = strtolower(trim($data[0]));
            $smeltTime = floatval($data[1]);

            if ($tierName === "" || $smeltTime <= 0) {
                $player->sendMessage(TextFormat::RED . "Invalid tier name or time.");
                return;
            }

            $this->tiers->set($tierName, $smeltTime);
            $this->tiers->save();
            $player->sendMessage(TextFormat::GREEN . "Created tier '$tierName' with $smeltTime seconds.");
        });

        $form->setTitle("§aCreate Tier");
        $form->addInput("Tier Name:");
        $form->addInput("Smelt Time (in seconds):", "e.g. 5");

        $player->sendForm($form);
    }

    private function openDeleteTierForm(Player $player): void {
        $tiers = array_map('strval', array_keys($this->tiers->getAll()));
        if (empty($tiers)) {
            $player->sendMessage(TextFormat::RED . "No tiers available to delete.");
            return;
        }

        $form = new \jojoe77777\FormAPI\CustomForm(function (Player $player, ?array $data) use ($tiers): void {
            if (!is_array($data)) {
                $player->sendMessage(TextFormat::RED . "Form cancelled.");
                return;
            }

            $index = $data[0] ?? null;
            if (!is_int($index) || !isset($tiers[$index])) {
                $player->sendMessage(TextFormat::RED . "Invalid tier selected.");
                return;
            }

            $tierName = $tiers[$index];
            $this->tiers->remove($tierName);
            $this->tiers->save();
            $player->sendMessage(TextFormat::GREEN . "Deleted tier '$tierName'.");
        });

        $form->setTitle("§cDelete Tier");
        $form->addDropdown("Select Tier to Delete:", $tiers);

        $player->sendForm($form);
    }

    private function listTiersToPlayer(Player $player): void {
        $allTiers = $this->tiers->getAll();
        if (empty($allTiers)) {
            $player->sendMessage(TextFormat::YELLOW . "No tiers created yet.");
            return;
        }

        $player->sendMessage(TextFormat::GOLD . "--- TierSmelter Tiers ---");
        foreach ($allTiers as $name => $time) {
            $player->sendMessage(TextFormat::YELLOW . "$name: " . TextFormat::WHITE . "$time seconds");
        }
    }

    private function setTierToFurnace(Player $player): void {
        $tiers = array_map('strval', array_keys($this->tiers->getAll()));
        if (empty($tiers)) {
            $player->sendMessage(TextFormat::RED . "No tiers available. Create some first.");
            return;
        }

        $form = new \jojoe77777\FormAPI\CustomForm(function (Player $player, ?array $data) use ($tiers): void {
            if (!is_array($data)) {
                $player->sendMessage(TextFormat::RED . "Form cancelled.");
                return;
            }

            $index = $data[0] ?? null;
            if (!is_int($index) || !isset($tiers[$index])) {
                $player->sendMessage(TextFormat::RED . "Invalid tier selected.");
                return;
            }

            $tierName = $tiers[$index];
            $block = $player->getTargetBlock(5);
            if ($block === null || $block->getTypeId() !== BlockTypeIds::BLAST_FURNACE) {
                $player->sendMessage(TextFormat::RED . "Look at a Blast Furnace to assign the tier.");
                return;
            }

            $pos = $block->getPosition();
            $furnaceKey = $this->getFurnaceKey($pos);
            $this->furnaces->set($furnaceKey, $tierName);
            $this->furnaces->save();

            $player->sendMessage(TextFormat::GREEN . "Blast Furnace set to tier '$tierName'.");
        });

        $form->setTitle("§bSet Tier to Furnace");
        $form->addDropdown("Select Tier:", $tiers);

        $player->sendForm($form);
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

        $event->cancel();

        $inventory = $tile->getInventory();
        $input = $inventory->getItem(0);
        $result = $event->getResult();

        $smeltTimeSeconds = (float)$this->tiers->get($tierName);
        $smeltTimeTicks = (int)ceil($smeltTimeSeconds * 20);

        if (!isset($this->smeltingQueue[$furnaceKey])) {
            // spawn one-time explosion at start
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

            // require fuel present (infinite)
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

                /** @phpstan-ignore-next-line */
                $pos->getWorld()->addSound($pos, new NoteSound(NoteInstrument::PIANO, 1));

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

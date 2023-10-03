<?php
declare(strict_types=1);

namespace pmmp\TesterPlugin;

use pocketmine\Server;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;

use jojoe77777\FormAPI\SimpleForm;

class Main extends PluginBase implements Listener{
    
    public function onEnable() : void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        
        @mkdir($this->getDataFolder());
       $this->saveDefaultConfig();
       $this->saveResource("config.yml");
       
    }
    
    public function onCommand(CommandSender $sender, Command $cmd, String $label, Array $args) : bool {
        
        if($cmd->getName() == "info"){
            $this->InfoUI($sender);
        }
        
        return true;
    }
    
    public function InfoUI($player){
        $form = new SimpleForm(function(Player $player, int $data = null){
        $result = $data;
        if($result === null){
            return true;
            }
            switch($result){
                case 0:
                    $this->About($player);
                break;
                
                case 1:
                    $this->Rules($player);
                break;
                
                case 2:
                    $this->Staff($player);
                break;
                
                case 3:
                    $this->Update($player);
                break;
                
                case 4:
                    
                break;
            }
        });
        $form->setTitle($this->getConfig()->get("InfoUI-Title"));
        $form->addButton($this->getConfig()->get("About-Button"), 0, "textures/ui/icon_book_writable");
        $form->addButton($this->getConfig()->get("Rules-Button"), 0, "textures/ui/recipe_book_icon");
        $form->addButton($this->getConfig()->get("Staff-Button"), 0, "textures/ui/icon_best3");
        $form->addButton($this->getConfig()->get("Update-Button"), 0, "textures/ui/mashup_world");
        $form->addButton($this->getConfig()->get("Exit-Button"), 0, "textures/ui/icon_import");
        $form->sendToPlayer($player);
    }
    
    public function About($player){
        $form = new SimpleForm(function(Player $player, int $data = null){
        $result = $data;
        if($data === null){
            return true;
            }
            switch($result){
                case 0:
                    $this->InfoUI($player);
                break;
            }
        });
        $form->setTitle($this->getConfig()->get("About-Title"));
        $form->setContent($this->getConfig()->get("About-Text"));
        $form->addButton($this->getConfig()->get("Back-Button"), 0, "textures/ui/refresh_light");
        $form->sendToPlayer($player);
    }
    
    public function Rules($player){
        $form = new SimpleForm(function(Player $player, int $data = null){
        $result = $data;
        if($result === null){
            return true;
            }
            switch($result){
                case 0:
                    $this->InfoUI($player);
                break;
            }
        });
        $form->setTitle($this->getConfig()->get("Rules-Title"));
        $form->setContent($this->getConfig()->get("Rules-Text"));
        $form->addButton($this->getConfig()->get("Back-Button"), 0, "textures/ui/refresh_light");
        $form->sendToPlayer($player);
    }
    
    public function Staff($player){
        $form = new SimpleForm(function(Player $player, int $data = null){
        $result = $data;
        if($result === null){
            return true;
            }
            switch($result){
                case 0:
                    $this->InfoUI($player);
                break;
            }
        });
        $form->setTitle($this->getConfig()->get("Staff-Title"));
        $form->setContent($this->getConfig()->get("Staff-Text"));
        $form->addButton($this->getConfig()->get("Back-Button"), 0, "textures/ui/refresh_light");
        $form->sendToPlayer($player);
    }
    
    public function Update($player){
        $form = new SimpleForm(function(Player $player, int $data = null){
        $result = $data;
        if($result === null){
            return true;
            }
            switch($result){
                case 0:
                    $this->InfoUI($player);
                break;
            }
        });
        # Form Set For Info
        $form->setTitle($this->getConfig()->get("Update-Title"));
        $form->setContent($this->getConfig()->get("Update-Text"));
        $form->addButton($this->getConfig()->get("Back-Button"), 0, "textures/ui/refresh_light");
        $form->sendToPlayer($player);
    }
}

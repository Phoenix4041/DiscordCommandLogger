<?php

declare(strict_types=1);

namespace Phoenix4041\DiscordCommandLogger;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\server\CommandEvent;
use pocketmine\utils\Config;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\player\Player;

class Main extends PluginBase implements Listener {

    private Config $config;
    private string $webhookUrl;

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->reloadConfig();
        $this->config = $this->getConfig();
        
        // Check if config is properly loaded
        if ($this->config->getAll() === null || empty($this->config->getAll())) {
            $this->getLogger()->warning("§eConfig file is empty or corrupted. Creating default configuration...");
            $this->createDefaultConfig();
            $this->reloadConfig();
            $this->config = $this->getConfig();
        }
        
        $this->webhookUrl = $this->config->get("webhook-url", "");
        
        if (empty($this->webhookUrl)) {
            $this->getLogger()->error("§cWebhook URL not configured! Please set it in config.yml");
            $this->getLogger()->info("§eExample: webhook-url: \"https://discord.com/api/webhooks/YOUR_WEBHOOK_ID/YOUR_WEBHOOK_TOKEN\"");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aDiscord Command Logger enabled");
    }

    public function onDisable(): void {
        $this->getLogger()->info("§cDiscord Command Logger disabled");
    }

    /**
     * Create default configuration if it doesn't exist or is corrupted
     */
    private function createDefaultConfig(): void {
        $defaultConfig = [
            "webhook-url" => "",
            "log-player-commands" => true,
            "log-console-commands" => true
        ];
        
        $configFile = $this->getDataFolder() . "config.yml";
        file_put_contents($configFile, yaml_emit($defaultConfig, YAML_UTF8_ENCODING));
        $this->getLogger()->info("§aDefault configuration created at: " . $configFile);
    }

    /**
     * Handle all commands (including player and console)
     */
    public function onServerCommand(CommandEvent $event): void {
        $sender = $event->getSender();
        $command = "/" . $event->getCommand();
        
        if ($sender instanceof ConsoleCommandSender) {
            if (!$this->config->get("log-console-commands", true)) {
                return;
            }
            
            $this->sendToDiscord(
                "Console",
                $command,
                "Console Command"
            );
        } elseif ($sender instanceof Player) {
            if (!$this->config->get("log-player-commands", true)) {
                return;
            }
            
            $this->sendToDiscord(
                $sender->getName(),
                $command,
                "Player Command"
            );
        }
    }

    /**
     * Send command information to Discord webhook
     */
    private function sendToDiscord(string $executor, string $command, string $type): void {
        $timestamp = date("F j, Y, g:i a");
        
        // Create simple text message
        $message = "{$timestamp} COMMAND: `{$command}` was sent by **{$executor}**";
        
        $data = [
            "username" => "Command Logger",
            "content" => $message
        ];

        // Send webhook asynchronously
        $this->sendWebhookAsync($data);
    }

    /**
     * Send webhook data asynchronously
     */
    private function sendWebhookAsync(array $data): void {
        $jsonData = json_encode($data);
        
        // Use cURL to send the webhook
        $this->getServer()->getAsyncPool()->submitTask(new class($this->webhookUrl, $jsonData) extends \pocketmine\scheduler\AsyncTask {
            private string $webhookUrl;
            private string $jsonData;

            public function __construct(string $webhookUrl, string $jsonData) {
                $this->webhookUrl = $webhookUrl;
                $this->jsonData = $jsonData;
            }

            public function onRun(): void {
                $ch = curl_init();
                
                curl_setopt_array($ch, [
                    CURLOPT_URL => $this->webhookUrl,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $this->jsonData,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json'
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 10
                ]);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($response === false || $httpCode !== 200) {
                    $this->setResult("Error sending webhook: " . curl_error($ch) . " (HTTP: $httpCode)");
                } else {
                    $this->setResult("success");
                }
                
                curl_close($ch);
            }

            public function onCompletion(): void {
                $result = $this->getResult();
                if ($result !== "success") {
                    error_log("[DiscordCommandLogger] " . $result);
                }
            }
        });
    }
}
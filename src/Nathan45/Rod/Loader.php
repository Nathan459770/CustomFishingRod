<?php

namespace Nathan45\Rod;

use pocketmine\data\bedrock\EntityLegacyIds;
use pocketmine\entity\animation\ArmSwingAnimation;
use pocketmine\entity\Attribute;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\entity\projectile\Projectile;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\ProjectileHitEntityEvent;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Durable;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemIds;
use pocketmine\item\ItemUseResult;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\Random;
use pocketmine\world\World;

class Loader extends PluginBase
{
    private static self $instance;
    public array $fishing = [];

    protected function onEnable(): void
    {
        $this->saveDefaultConfig();
        ItemFactory::getInstance()->register(new Rod(new ItemIdentifier(ItemIds::FISHING_ROD, 0)), true);
        EntityFactory::getInstance()->register(
            Hook::class,
            function(World $world, CompoundTag $nbt) : Hook{return new Hook(EntityDataHelper::parseLocation($nbt, $world), null, $nbt);},
            ["Fishing Hook", "minecraft:fishing_hook"],
            EntityLegacyIds::FISHING_HOOK
        );
        CreativeInventory::reset();
    }

    public function getFishingHook(Player $player): null|Hook
    {
        return $this->fishing[$player->getName()] ?? null;
    }

    public function setFishingHook(Player $player, null|Hook $hook = null): void{
        $this->fishing[$player->getName()] = $hook;
    }

    public static function getInstance(): self{
        return self::$instance;
    }

    protected function onLoad(): void{
        self::$instance = $this;
    }


}

class Rod extends Durable
{

    private Config $config;

    public function __construct(ItemIdentifier $identifier, string $name = "Fishing Rod")
    {
        parent::__construct($identifier, $name);
        $this->config = new Config(Loader::getInstance()->getDataFolder() . "config.yml", Config::YAML);
    }

    public function getMaxStackSize(): int
    {
        return 1;
    }

    public function getCooldownTicks(): int
    {
        return $this->config->get("cooldown", 5);
    }

    public function getMaxDurability(): int
    {
        return $this->config->get("durability", 355);
    }

    public function onClickAir(Player $player, Vector3 $directionVector): ItemUseResult
    {
        if (!$player->hasItemCooldown($this)) {
            $player->resetItemCooldown($this);

            if (!Loader::getInstance()->getFishingHook($player)) {
                $hook = new Hook($player->getLocation(), $player, new CompoundTag());
                $hook->spawnToAll();
            } else {
                $hook = Loader::getInstance()->getFishingHook($player);
                $hook->delete();
                Loader::getInstance()->setFishingHook($player);
            }
            $player->broadcastAnimation(new ArmSwingAnimation($player));
            return ItemUseResult::SUCCESS();
        }
        return ItemUseResult::FAIL();
    }

    public function getProjectileEntityType(): string{
        return "Hook";
    }

    public function getThrowForce(): float{
        return $this->config->get("ThrowForce", 10);
    }
}

class Hook extends Projectile
{
    protected $gravity;
    private Config $config;

    public function __construct(Location $location, ?Entity $player, ?CompoundTag $nbt = null)
    {
        parent::__construct($location, $player, $nbt);
        $this->config = new Config(Loader::getInstance()->getDataFolder() . "config.yml", Config::YAML);
        $this->gravity = $this->config->get("gravity", 0.35);
        if($player instanceof Player){
            $this->setPosition($this->getLocation()->add(0, $player->getEyeHeight() - 0.1, 0));
            $this->setMotion($player->getDirectionVector()->multiply(0.4));
            Loader::getInstance()->setFishingHook($player, $this);
            $this->handleHookCasting($this->motion->x, $this->motion->y, $this->motion->z, 1.5, 1.0);
        }
    }

    public static function getNetworkTypeId(): string {
        return EntityIds::FISHING_HOOK;
    }

    public function getInitialSizeInfo(): EntitySizeInfo {
        return new EntitySizeInfo(0.25, 0.25);
    }

    public function handleHookCasting(float $x, float $y, float $z, float $f1, float $f2): void{
        $rand = new Random();
        $f = sqrt($x * $x + $y * $y + $z * $z);
        $x /= $f;
        $y /= $f;
        $z /= $f;
        $x += $rand->nextSignedFloat() * 0.007499999832361937 * $f2;
        $y += $rand->nextSignedFloat() * 0.007499999832361937 * $f2;
        $z += $rand->nextSignedFloat() * 0.007499999832361937 * $f2;
        $x *= $f1;
        $y *= $f1;
        $z *= $f1;
        $this->motion->x += $x;
        $this->motion->y += $y;
        $this->motion->z += $z;
    }

    protected function onHitEntity(Entity $entityHit, RayTraceResult $hitResult): void
    {
        $event = new ProjectileHitEntityEvent($this, $hitResult, $entityHit);
        $event->call();
        $damage = $this->getResultDamage();

        if ($this->getOwningEntity() instanceof Entity) {
            $ev = new EntityDamageByEntityEvent($this, $entityHit, EntityDamageEvent::CAUSE_PROJECTILE, $damage);
            $entityHit->attack($ev);
            $kb = $this->getKnockBackFor($entityHit, $this->location->x - $entityHit->location->x, $this->location->z - $entityHit->location->z);
            if($kb instanceof Vector3) $entityHit->setMotion($kb);

        }
        $this->isCollided = true;
        $this->delete();
    }

    public function getKnockBackFor(Entity $entity, float $x, float $z) : null|Vector3{
        $f = sqrt($x * $x + $z * $z);
        if($f <= 0 || !(mt_rand() / mt_getrandmax() > $entity->getAttributeMap()->get(Attribute::KNOCKBACK_RESISTANCE)->getValue())) return null;

        $f = 1 / $f;

        $kbx = $this->config->get("kb_x", 0.4);
        $kby = $this->config->get("kb_y", 0.4);
        $kbz = $this->config->get("kb_z", 0.4);

        $motionX = $this->motion->x / 2;
        $motionY = $entity->motion->y / 2;
        $motionZ = $this->motion->z / 2;
        $motionX += $x * $f * $kbx;
        $motionY += $kby;
        $motionZ += $z * $f * $kbz;

        if($motionY > $kby) $motionY = $kby;
        return new Vector3($motionX, $motionY, $motionZ);
    }

    public function entityBaseTick(int $tickDiff = 1): bool{
        $hasUpdate = parent::entityBaseTick($tickDiff);
        $owner = $this->getOwningEntity();

        if(!($owner instanceof Player && ($owner->getInventory()->getItemInHand() instanceof Rod && $owner->isAlive() && !$owner->isClosed()))) $this->delete();

        return $hasUpdate;
    }

    public function delete(): void
    {
        $this->flagForDespawn();
        if ($this->getOwningEntity() instanceof Player) Loader::getInstance()->setFishingHook($this->getOwningEntity());
    }
}
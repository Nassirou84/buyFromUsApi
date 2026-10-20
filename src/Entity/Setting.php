<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use Symfony\Component\Serializer\Attribute\Groups;
use App\Repository\SettingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ApiResource(
    operations: [
        new \ApiPlatform\Metadata\Get(
            security: "is_granted('ROLE_ADMIN')"
        ),
        new \ApiPlatform\Metadata\Post(
            security: "is_granted('ROLE_SUPER_ADMIN')"
        ),
        new GetCollection(
            paginationEnabled: false,
            security: "is_granted('ROLE_ADMIN')"
        ),
        new \ApiPlatform\Metadata\Patch(
            security: "is_granted('ROLE_SUPER_ADMIN')"
        )
    ],
    denormalizationContext: ['groups' => ['setting:write']],
    normalizationContext: ['groups' => ['setting:read']],
)]
#[ORM\Entity(repositoryClass: SettingRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_SETTING_NAME', fields: ['name'])]
class Setting
{

    /* True or false values */
    const TRUE = 'true';
    const FALSE = 'false';

    /* Scopes */
    const SCOPE_GLOBAL = 'global';
    const SCOPE_FRONT_END = 'front_end';
    const SCOPE_USER = 'user';
    const SCOPE_ADMIN = 'admin';

    /* Settings Titles */
    const WELCOME_PROMO_CODE = 'welcome_promo_code';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['setting:read'])]
    /** @phpstan-ignore-next-line */
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['setting:read', 'setting:write'])]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    #[Groups(['setting:read', 'setting:write'])]
    private ?string $value = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['setting:read', 'setting:write'])]
    private ?string $scope = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(string $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;

        return $this;
    }
}
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BrowserPushSubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BrowserPushSubscriptionRepository::class)]
#[ORM\Table(name: 'browser_push_subscriptions')]
#[ORM\Index(columns: ['user_id', 'updated_at'], name: 'idx_browser_push_user_updated')]
class BrowserPushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'text')]
    private string $endpoint;

    #[ORM\Column(length: 64, unique: true)]
    private string $endpointHash;

    #[ORM\Column(length: 255)]
    private string $publicKey;

    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(length: 12)]
    private string $contentEncoding = 'aes128gcm';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $categories = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $user,
        string $endpoint,
        string $endpointHash,
        string $publicKey,
        string $authToken,
    ) {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->endpointHash = $endpointHash;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getUser(): User { return $this->user; }
    public function getEndpoint(): string { return $this->endpoint; }
    public function getEndpointHash(): string { return $this->endpointHash; }
    public function getPublicKey(): string { return $this->publicKey; }
    public function getAuthToken(): string { return $this->authToken; }
    public function getContentEncoding(): string { return $this->contentEncoding; }

    /** @return list<string> */
    public function getCategories(): array { return $this->categories; }

    /** @param list<string> $categories */
    public function update(
        User $user,
        string $endpoint,
        string $publicKey,
        string $authToken,
        array $categories,
    ): void {
        $this->user = $user;
        $this->endpoint = $endpoint;
        $this->publicKey = $publicKey;
        $this->authToken = $authToken;
        $this->categories = array_values(array_unique($categories));
        $this->updatedAt = new \DateTimeImmutable();
    }
}

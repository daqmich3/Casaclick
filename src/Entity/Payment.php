<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
class Payment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: 'App\\Entity\\Application')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Application $application = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, completed, failed, refunded, cancelled

    #[ORM\Column(length: 50)]
    private string $paymentMethod = 'cash'; // 'cash', 'bank_transfer', 'gcash', 'paymaya', 'credit_card'

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transactionId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\ManyToOne(targetEntity: 'App\\Entity\\User')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $processedBy = null; // Admin or landlord who processed the payment

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $paymongoLinkId = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $paymongoCheckoutUrl = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    public function setApplication(?Application $application): static
    {
        $this->application = $application;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        // Bump sync revision when status changes (web/mobile live poll).
        $this->paidAt = new DateTimeImmutable();

        return $this;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $paymentMethod): static
    {
        $this->paymentMethod = $paymentMethod;
        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): static
    {
        $this->transactionId = $transactionId;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getProcessedBy(): ?User
    {
        return $this->processedBy;
    }

    public function setProcessedBy(?User $processedBy): static
    {
        $this->processedBy = $processedBy;
        return $this;
    }

    public function getPaymongoLinkId(): ?string
    {
        return $this->paymongoLinkId;
    }

    public function setPaymongoLinkId(?string $paymongoLinkId): static
    {
        $this->paymongoLinkId = $paymongoLinkId;
        return $this;
    }

    public function getPaymongoCheckoutUrl(): ?string
    {
        return $this->paymongoCheckoutUrl;
    }

    public function setPaymongoCheckoutUrl(?string $paymongoCheckoutUrl): static
    {
        $this->paymongoCheckoutUrl = $paymongoCheckoutUrl;
        return $this;
    }
}


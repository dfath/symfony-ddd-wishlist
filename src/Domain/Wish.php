<?php

namespace Wishlist\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Money\Currency;
use Money\Money;
use Webmozart\Assert\Assert;
use Wishlist\Domain\Exception\DepositDoesNotExistException;
use Wishlist\Domain\Exception\DepositIsTooSmallException;
use Wishlist\Domain\Exception\WishIsFulfilledException;
use Wishlist\Domain\Exception\WishIsUnpublishedException;

class Wish
{
    private $id;
    private $name;
    private $expense;
    /** @var Deposit[] */
    private $deposits;
    private $published = false;
    private $createdAt;
    private $updatedAt;

    public function __construct(
        WishId $id,
        WishName $name,
        Expense $expense,
        DateTimeImmutable $createdAt = null
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->expense = $expense;
        $this->deposits = new ArrayCollection();
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
        $this->updatedAt = $createdAt ?? new DateTimeImmutable();
    }

    public function deposit(Money $amount): Deposit
    {
        $this->assertCanDeposit($amount);

        $deposit = new Deposit(DepositId::next(), $this, $amount);
        $this->deposits->add($deposit);

        return $deposit;
    }

    private function assertCanDeposit(Money $amount)
    {
        if (!$this->published) {
            throw new WishIsUnpublishedException($this->getId());
        }

        if ($this->isFulfilled()) {
            throw new WishIsFulfilledException($this->getId());
        }

        if ($amount->lessThan($this->getFee())) {
            throw new DepositIsTooSmallException($amount, $this->getFee());
        }

        Assert::true(
            $amount->isSameCurrency($this->expense->getPrice()),
            'Deposit currency must match the price\'s one.'
        );
    }

    public function isFulfilled(): bool
    {
        return $this->getFund()->greaterThanOrEqual($this->expense->getPrice());
    }

    public function withdraw(DepositId $depositId)
    {
        $this->assertCanWithdraw();

        $deposit = $this->getDepositById($depositId);
        $this->deposits->removeElement($deposit);
    }

    private function assertCanWithdraw()
    {
        if (!$this->published) {
            throw new WishIsUnpublishedException($this->getId());
        }

        if ($this->isFulfilled()) {
            throw new WishIsFulfilledException($this->getId());
        }
    }

    private function getDepositById(DepositId $depositId): Deposit
    {
        $deposit = $this->deposits->filter(
            function (Deposit $deposit) use ($depositId) {
                return $deposit->getId()->equalTo($depositId);
            }
        )->first();

        if (!$deposit) {
            throw new DepositDoesNotExistException($depositId);
        }

        return $deposit;
    }

    public function calculateSurplusFunds(): Money
    {
        $difference = $this->getPrice()->subtract($this->getFund());

        return $difference->isNegative()
            ? $difference->absolute()
            : new Money(0, $this->getCurrency());
    }

    public function predictFulfillmentDateBasedOnFee(): DateTimeInterface
    {
        $daysToGo = ceil(
            $this->getPrice()
            ->divide($this->getFee()->getAmount())
            ->getAmount()
        );

        return $this->createFutureDate($daysToGo);
    }

    public function predictFulfillmentDateBasedOnFund(): DateTimeInterface
    {
        $daysToGo = ceil(
            $this->getPrice()
            ->subtract($this->getFund())
            ->divide($this->getFee()->getAmount())
            ->getAmount()
        );

        return $this->createFutureDate($daysToGo);
    }

    private function createFutureDate($daysToGo): DateTimeInterface
    {
        return (new DateTimeImmutable())->add(new DateInterval("P{$daysToGo}D"));
    }

    public function publish()
    {
        $this->published = true;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function unpublish()
    {
        $this->published = false;
        $this->updatedAt = new DateTimeImmutable();
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getId(): WishId
    {
        return $this->id;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function getPrice(): Money
    {
        return $this->expense->getPrice();
    }

    public function changePrice(Money $amount)
    {
        $this->expense = $this->expense->changePrice($amount);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getFee(): Money
    {
        return $this->expense->getFee();
    }

    public function changeFee(Money $amount)
    {
        $this->expense = $this->expense->changeFee($amount);
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getFund(): Money
    {
        return array_reduce($this->deposits->toArray(), function (Money $fund, Deposit $deposit) {
            return $fund->add($deposit->getMoney());
        }, $this->expense->getInitialFund());
    }

    /**
     * @return array|Deposit[]
     */
    public function getDeposits(): array
    {
        return $this->deposits->toArray();
    }

    public function getCurrency(): Currency
    {
        return $this->expense->getCurrency();
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }
}

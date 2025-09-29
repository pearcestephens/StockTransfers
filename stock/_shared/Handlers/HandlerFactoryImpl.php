<?php
declare(strict_types=1);
/**
 * File: HandlerFactoryImpl.php
 * Purpose: Resolve handlers for pack/send modes
 * Author: GitHub Copilot
 * Last Modified: 2025-09-29
 * Dependencies: HandlerInterface implementations
 */

namespace Modules\Transfers\Stock\Shared\Handlers;

use Modules\Transfers\Stock\Shared\Services\Modes;
use RuntimeException;

final class HandlerFactoryImpl
{
    /** @var array<string, HandlerInterface> */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [];
        $this->register(new PackedNotSentHandler());
        $this->register(new CourierManualNzcHandler());
        $this->register(new CourierManualNzpHandler());
        $this->register(new PickupHandler());
        $this->register(new InternalDriveHandler());
        $this->register(new DepotDropHandler());
        $this->register(new ReceiveOnlyHandler());
    }

    public function register(HandlerInterface $handler): void
    {
        $this->handlers[$handler->mode()] = $handler;
    }

    public function resolve(string $mode): HandlerInterface
    {
        $mode = strtoupper($mode);
        if (!isset($this->handlers[$mode])) {
            throw new RuntimeException('No handler registered for mode ' . $mode);
        }
        return $this->handlers[$mode];
    }

    /**
     * @return list<string>
     */
    public function supportedModes(): array
    {
        return array_keys($this->handlers);
    }
}

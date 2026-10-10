<?php

namespace App\Service;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMqMessengerService
{
  private ?AMQPStreamConnection $connection = null;
  private ?AMQPChannel $channel = null;

  public function __construct(
    private string $host,
    private int $port,
    private string $user,
    private string $password,
    private string $vhost
  ) {
  }

  public function getChannel(): AMQPChannel
  {
    if ($this->connection === null || !$this->connection->isConnected()) {
      $this->connection = new AMQPStreamConnection(
        $this->host,
        $this->port,
        $this->user,
        $this->password,
        $this->vhost
      );
      $this->channel = $this->connection->channel();
    }
    return $this->channel;
  }

  public function setupExchangeAndQueue(string $exchangeName, string $exchangeType, string $queueName, string $routingKey): void
  {
    $channel = $this->getChannel();
    $channel->exchange_declare($exchangeName, $exchangeType, false, true, false);
    $channel->queue_declare($queueName, false, true, false, false);
    $channel->queue_bind($queueName, $exchangeName, $routingKey);
  }

  public function publishMessage(string $exchangeName, string $routingKey, mixed $message): void
  {
    $channel = $this->getChannel();
    $payload = json_encode($message, JSON_THROW_ON_ERROR);
    $message = new AMQPMessage($payload, [
      'content_type' => 'application/json',
      'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
    ]);
    $channel->basic_publish($message, $exchangeName, $routingKey);
  }


  public function getConsumerChannel(): AMQPChannel
  {
    return $this->getChannel();
  }

  public function closeConnection(): void
  {
    if ($this->channel !== null) {
      $this->channel->close();
    }
    if ($this->connection !== null) {
      $this->connection->close();
    }
  }
}
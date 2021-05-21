<?php

declare(strict_types=1);

namespace Codeception\Module;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Interfaces\RequiresPackage;
use Codeception\Module;
use Codeception\TestInterface;
use Exception;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * This module interacts with message broker software that implements
 * the Advanced Message Queuing Protocol (AMQP) standard. For example, RabbitMQ (tested).
 *
 * <div class="alert alert-info">
 * To use this module with Composer you need <em>"php-amqplib/php-amqplib": "~2.4"</em> package.
 * </div>
 *
 * ## Config
 *
 * * host: localhost - host to connect
 * * username: guest - username to connect
 * * password: guest - password to connect
 * * vhost: '/' - vhost to connect
 * * cleanup: true - defined queues will be purged before running every test.
 * * queues: [mail, twitter] - queues to cleanup
 * * single_channel - create and use only one channel during test execution
 *
 * ### Example
 *
 *     modules:
 *         enabled:
 *             - AMQP:
 *                 host: 'localhost'
 *                 port: '5672'
 *                 username: 'guest'
 *                 password: 'guest'
 *                 vhost: '/'
 *                 queues: [queue1, queue2]
 *                 single_channel: false
 *
 * ## Public Properties
 *
 * * connection - AMQPStreamConnection - current connection
 */
class AMQP extends Module implements RequiresPackage
{
    /**
     * @var array
     */
    protected $config = [
        'host'           => 'localhost',
        'username'       => 'guest',
        'password'       => 'guest',
        'port'           => '5672',
        'vhost'          => '/',
        'cleanup'        => true,
        'single_channel' => false,
        'queues'         => []
    ];

    /**
     * @var AMQPStreamConnection
     */
    public $connection;

    /**
     * @var int
     */
    protected $channelId;

    /**
     * @var string[]
     */
    protected $requiredFields = ['host', 'username', 'password', 'vhost'];

    public function _requires()
    {
        return [\PhpAmqpLib\Connection\AMQPStreamConnection::class => '"php-amqplib/php-amqplib": "~2.4"'];
    }

    public function _initialize()
    {
        $host = $this->config['host'];
        $port = $this->config['port'];
        $username = $this->config['username'];
        $password = $this->config['password'];
        $vhost = $this->config['vhost'];

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $username, $password, $vhost);
        } catch (Exception $e) {
            throw new ModuleException(__CLASS__, $e->getMessage() . ' while establishing connection to MQ server');
        }
    }

    public function _before(TestInterface $test)
    {
        if ($this->config['cleanup']) {
            $this->cleanup();
        }
    }

    /**
     * Sends message to exchange by sending exchange name, message
     * and (optionally) a routing key
     *
     * ``` php
     * <?php
     * $I->pushToExchange('exchange.emails', 'thanks');
     * $I->pushToExchange('exchange.emails', new AMQPMessage('Thanks!'));
     * $I->pushToExchange('exchange.emails', new AMQPMessage('Thanks!'), 'severity');
     * ```
     *
     * @param string|AMQPMessage $message
     */
    public function pushToExchange(string $exchange, $message, string $routing_key = null): void
    {
        $message = $message instanceof AMQPMessage
            ? $message
            : new AMQPMessage($message);
        $this->getChannel()->basic_publish($message, $exchange, $routing_key);
    }

    /**
     * Sends message to queue
     *
     * ``` php
     * <?php
     * $I->pushToQueue('queue.jobs', 'create user');
     * $I->pushToQueue('queue.jobs', new AMQPMessage('create'));
     * ```
     *
     * @param string|AMQPMessage $message
     */
    public function pushToQueue(string $queue, $message): void
    {
        $message = $message instanceof AMQPMessage
            ? $message
            : new AMQPMessage($message);

        $this->getChannel()->queue_declare($queue);
        $this->getChannel()->basic_publish($message, '', $queue);
    }

    /**
     * Declares an exchange
     *
     * This is an alias of method `exchange_declare` of `PhpAmqpLib\Channel\AMQPChannel`.
     *
     * ```php
     * <?php
     * $I->declareExchange(
     *     'nameOfMyExchange', // exchange name
     *     'topic' // exchange type
     * )
     * ```
     *
     * @return mixed|null
     */
    public function declareExchange(
        string $exchange,
        string $type,
        bool $passive = false,
        bool $durable = false,
        bool $auto_delete = true,
        bool $internal = false,
        bool $nowait = false,
        array $arguments = null,
        int $ticket = null
    ) {
        return $this->getChannel()->exchange_declare(
            $exchange,
            $type,
            $passive,
            $durable,
            $auto_delete,
            $internal,
            $nowait,
            $arguments,
            $ticket
        );
    }

    /**
     * Declares queue, creates if needed
     *
     * This is an alias of method `queue_declare` of `PhpAmqpLib\Channel\AMQPChannel`.
     *
     * ```php
     * <?php
     * $I->declareQueue(
     *     'nameOfMyQueue', // exchange name
     * )
     * ```
     *
     * @return mixed|null
     */
    public function declareQueue(
        string $queue = '',
        bool $passive = false,
        bool $durable = false,
        bool $exclusive = false,
        bool $auto_delete = true,
        bool $nowait = false,
        array $arguments = null,
        int $ticket = null
    ): ?array {
        return $this->getChannel()->queue_declare(
            $queue,
            $passive,
            $durable,
            $exclusive,
            $auto_delete,
            $nowait,
            $arguments,
            $ticket
        );
    }

    /**
     * Binds a queue to an exchange
     *
     * This is an alias of method `queue_bind` of `PhpAmqpLib\Channel\AMQPChannel`.
     *
     * ```php
     * <?php
     * $I->bindQueueToExchange(
     *     'nameOfMyQueueToBind', // name of the queue
     *     'transactionTracking.transaction', // exchange name to bind to
     *     'your.routing.key' // Optionally, provide a binding key
     * )
     * ```
     *
     * @return mixed|null
     */
    public function bindQueueToExchange(
        string $queue,
        string $exchange,
        string $routing_key = '',
        bool $nowait = false,
        array $arguments = null,
        int $ticket = null
    ) {
        return $this->getChannel()->queue_bind(
            $queue,
            $exchange,
            $routing_key,
            $nowait,
            $arguments,
            $ticket
        );
    }

    /**
     * Add a queue to purge list
     */
    public function scheduleQueueCleanup(string $queue): void
    {
        if (!in_array($queue, $this->config['queues'])) {
            $this->config['queues'][] = $queue;
        }
    }

    /**
     * Checks if message containing text received.
     *
     * **This method drops message from queue**
     * **This method will wait for message. If none is sent the script will stuck**.
     *
     * ``` php
     * <?php
     * $I->pushToQueue('queue.emails', 'Hello, davert');
     * $I->seeMessageInQueueContainsText('queue.emails','davert');
     * ```
     */
    public function seeMessageInQueueContainsText(string $queue, string $text): void
    {
        $msg = $this->getChannel()->basic_get($queue);
        if ($msg === null) {
            $this->fail("Message was not received");
        }
        if (!$msg instanceof AMQPMessage) {
            $this->fail("Received message is not format of AMQPMessage");
        }
        $this->debugSection("Message", $msg->body);
        $this->assertStringContainsString($text, $msg->body);
    }

    /**
     * Count messages in queue.
     */
    public function _countMessage(string $queue): int
    {
        [$queue, $messageCount] = $this->getChannel()->queue_declare($queue, true);
        return $messageCount;
    }

    /**
     * Checks that queue have expected number of message
     *
     * ``` php
     * <?php
     * $I->pushToQueue('queue.emails', 'Hello, davert');
     * $I->seeNumberOfMessagesInQueue('queue.emails',1);
     * ```
     */
    public function seeNumberOfMessagesInQueue(string $queue, int $expected): void
    {
        $messageCount = $this->_countMessage($queue);
        $this->assertEquals($expected, $messageCount);
    }

    /**
     * Checks that queue is empty
     *
     * ``` php
     * <?php
     * $I->pushToQueue('queue.emails', 'Hello, davert');
     * $I->purgeQueue('queue.emails');
     * $I->seeQueueIsEmpty('queue.emails');
     * ```
     */
    public function seeQueueIsEmpty(string $queue): void
    {
        $messageCount = $this->_countMessage($queue);
        $this->assertEquals(0, $messageCount);
    }

    /**
     * Checks if queue is not empty.
     *
     * ``` php
     * <?php
     * $I->pushToQueue('queue.emails', 'Hello, davert');
     * $I->dontSeeQueueIsEmpty('queue.emails');
     * ```
     */
    public function dontSeeQueueIsEmpty(string $queue): void
    {
        $messageCount = $this->_countMessage($queue);
        $this->assertNotEquals(0, $messageCount);
    }

    /**
     * Takes last message from queue.
     *
     * ``` php
     * <?php
     * $message = $I->grabMessageFromQueue('queue.emails');
     * ```
     */
    public function grabMessageFromQueue(string $queue): ?AMQPMessage
    {
        return $this->getChannel()->basic_get($queue);
    }

    /**
     * Purge a specific queue defined in config.
     *
     * ``` php
     * <?php
     * $I->purgeQueue('queue.emails');
     * ```
     */
    public function purgeQueue(string $queue = ''): void
    {
        if (! in_array($queue, $this->config['queues'])) {
            throw new ModuleException(__CLASS__, "'{$queue}' doesn't exist in queues config list");
        }

        $this->getChannel()->queue_purge($queue, true);
    }

    /**
     * Purge all queues defined in config.
     *
     * ``` php
     * <?php
     * $I->purgeAllQueues();
     * ```
     */
    public function purgeAllQueues(): void
    {
        $this->cleanup();
    }

    protected function getChannel(): AMQPChannel
    {
        if ($this->config['single_channel'] && $this->channelId === null) {
            $this->channelId = $this->connection->get_free_channel_id();
        }
        return $this->connection->channel($this->channelId);
    }

    protected function cleanup(): void
    {
        if (!isset($this->config['queues'])) {
            throw new ModuleException(__CLASS__, "please set queues for cleanup");
        }
        if (!$this->connection) {
            return;
        }
        foreach ($this->config['queues'] as $queue) {
            try {
                $this->getChannel()->queue_purge($queue);
            } catch (AMQPProtocolChannelException $e) {
                // ignore if exchange/queue doesn't exist and rethrow exception if it's something else
                if ($e->getCode() !== 404) {
                    throw $e;
                }
            }
        }
    }
}

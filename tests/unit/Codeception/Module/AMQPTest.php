<?php

namespace App\Tests\unit\Codeception\Module;

use Codeception\Exception\ModuleConfigException;
use Codeception\Exception\ModuleException;
use Codeception\Lib\ModuleContainer;
use Codeception\Module\AMQP;
use Codeception\PHPUnit\TestCase;
use Codeception\Util\Stub;
use Exception;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Class AMQPTest
 * @package App\Tests\unit\Codeception\Module
 */
final class AMQPTest extends TestCase
{
    protected const QUEUES    = [
        'queue1',
        'queue2',
        'queue3',
        'test-queue',
    ];
    protected const EXCHANGES = [
        'test-exchange',
    ];

    protected $config = [
        'host'     => 'localhost',
        'username' => 'guest',
        'password' => 'guest',
        'port'     => '5672',
        'vhost'    => '/',
        'cleanup'  => false,
        'queues'   => ['queue1'],
    ];

    /**
     * @var AMQP|null
     */
    protected $module;

    /**
     * @throws ModuleConfigException
     * @throws ModuleException
     * @throws Exception
     */
    protected function setUp(): void
    {
        $container = Stub::make(ModuleContainer::class);
        $this->module = new AMQP($container);
        $this->module->_setConfig($this->config);
        $res = @stream_socket_client('tcp://localhost:5672');
        if ($res === false) {
            self::markTestSkipped('AMQP is not running');
        }

        $this->module->_initialize();
        $connection = $this->module->connection;
        $connection->channel()->queue_declare('queue1');
    }

    /**
     * @throws Exception
     */
    protected function tearDown(): void
    {
        foreach (self::QUEUES as $queue) {
            $this->module->connection->channel()->queue_delete($queue);
        }
        foreach (self::EXCHANGES as $queue) {
            $this->module->connection->channel()->exchange_delete($queue);
        }
    }

    public function testPushToQueue(): void
    {
        $this->module->pushToQueue('queue1', 'hello');
        $this->module->seeMessageInQueueContainsText('queue1', 'hello');
    }

    /**
     * @throws ModuleException
     */
    public function testCountQueue(): void
    {
        $this->module->pushToQueue('queue1', 'hello');
        $this->module->pushToQueue('queue1', 'world');
        $this->module->dontSeeQueueIsEmpty('queue1');
        $this->module->seeNumberOfMessagesInQueue('queue1', 2);
        $this->module->purgeQueue('queue1');
        $this->module->seeQueueIsEmpty('queue1');
    }

    public function testPushToExchange(): void
    {
        $queue = 'test-queue';
        $exchange = 'test-exchange';
        $topic = 'test.3';
        $message = 'test-message';

        $this->module->declareExchange($exchange, 'topic', false, true, false);
        $this->module->declareQueue($queue, false, true, false, false);
        $this->module->bindQueueToExchange($queue, $exchange, 'test.#');

        $this->module->pushToExchange($exchange, $message, $topic);
        $this->module->seeMessageInQueueContainsText($queue, $message);
    }

    /**
     * @throws AMQPProtocolChannelException
     * @throws ModuleException
     */
    public function testPurgeAllQueue(): void
    {
        $this->module->pushToQueue('queue1', 'hello');
        $this->module->pushToQueue('queue2', 'world');
        $this->module->pushToQueue('queue3', '!!!');
        $this->module->dontSeeQueueIsEmpty('queue1');
        $this->module->dontSeeQueueIsEmpty('queue2');
        $this->module->dontSeeQueueIsEmpty('queue3');
        $this->module->scheduleQueueCleanup('queue2');
        $this->module->purgeAllQueues();
        $this->module->seeQueueIsEmpty('queue1');
        $this->module->seeQueueIsEmpty('queue2');
        $this->module->dontSeeQueueIsEmpty('queue3');
    }

    public function testGrabMessageFromQueue(): void
    {
        $this->module->pushToQueue('queue1', 'hello');
        $message = $this->module->grabMessageFromQueue('queue1');
        self::assertInstanceOf(AMQPMessage::class, $message);
        self::assertEquals('hello', $message->body);
    }
}

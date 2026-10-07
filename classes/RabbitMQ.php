<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Exception\AMQPExceptionInterface;
	use PhpAmqpLib\Message\AMQPMessage;

	class RabbitMQ {
		// Instance of RabbitMQ.
		private static $instance = null;

		// How long to wait for the broker to confirm a publish.
		const CONFIRM_TIMEOUT = 10;

		private $rabbitmq;
		private $connection;
		private $channel;

		// Have we started consuming?
		private $consuming = false;

		// Was the last publish nacked by the broker?
		private $nacked = false;

		/**
		 * Get the RabbitMQ instance.
		 *
		 * @return RabbitMQ instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new RabbitMQ();
			}

			return self::$instance;
		}

		public function setRabbitMQ($rabbitmq) {
			$this->rabbitmq = $rabbitmq;
		}

		private function connect() {
			if ($this->connection !== null) { return; }

			$heartbeat = intval($this->rabbitmq['heartbeat'] ?? 0);

			try {
				// php-amqplib requires read_write_timeout to be at least 2x the heartbeat.
				$this->connection = new AMQPStreamConnection($this->rabbitmq['host'], $this->rabbitmq['port'], $this->rabbitmq['user'], $this->rabbitmq['pass'],
				                                             '/', false, 'AMQPLAIN', null, 'en_US', 3.0, max(3.0, $heartbeat * 2), null, false, $heartbeat);
				$this->channel = $this->connection->channel();

				$this->channel->confirm_select();
				$this->channel->set_nack_handler(function ($msg) { $this->nacked = true; });
			} catch (Exception $ex) {
				$this->reset();

				throw $ex;
			}
		}

		/**
		 * Forget the current connection (eg after it has died) so that the
		 * next use reconnects.
		 */
		private function reset() {
			try {
				if ($this->connection !== null) { $this->connection->close(); }
			} catch (Throwable $ex) { }

			$this->connection = null;
			$this->channel = null;
		}

		/**
		 * Run a function against our channel.
		 *
		 * If the connection has died and we are not consuming, then reconnect
		 * and try once more. Processes that consume let the exception bubble up
		 * and get restarted instead, as reconnecting would lose their consumers.
		 *
		 * @param $function Function to call, given the channel.
		 * @return Result of $function
		 */
		public function withChannel($function) {
			for ($attempt = 1; ; $attempt++) {
				try {
					$this->connect();
					return $function($this->channel);
				} catch (AMQPExceptionInterface $ex) {
					if ($this->consuming || $attempt >= 2) { throw $ex; }

					$this->reset();
				}
			}
		}

		/**
		 * Publish a message and wait for the broker to confirm it.
		 *
		 * @param $msg AMQPMessage to publish, or a function that is given the
		 *             channel and returns one.
		 * @param $exchange Exchange to publish to.
		 * @param $routingKey Routing key.
		 * @param $prepare Optional function to call with the channel before
		 *                 publishing (eg to declare things).
		 */
		public function publish($msg, $exchange, $routingKey, $prepare = null) {
			$this->withChannel(function ($channel) use ($msg, $exchange, $routingKey, $prepare) {
				if ($prepare !== null) { $prepare($channel); }
				$message = is_callable($msg) ? $msg($channel) : $msg;

				$this->nacked = false;
				$channel->basic_publish($message, $exchange, $routingKey);
				$channel->wait_for_pending_acks(self::CONFIRM_TIMEOUT);

				if ($this->nacked) {
					throw new Exception('Message to ' . $exchange . '/' . $routingKey . ' was rejected by RabbitMQ.');
				}
			});
		}

		/**
		 * Add a consumer for a queue, with manual acks.
		 *
		 * Once this is called, this process will no longer reconnect if the
		 * connection dies.
		 *
		 * @param $queue Queue to consume.
		 * @param $function Function to call with each message.
		 * @param $prefetch How many unacked messages to allow at once.
		 */
		public function consumeQueue($queue, $function, $prefetch = 1) {
			$this->consuming = true;

			$this->getChannel()->basic_qos(null, $prefetch, null);
			$this->getChannel()->basic_consume($queue, '', false, false, false, false, $function);
		}

		/**
		 * Stop consuming from Rabbit MQ.
		 */
		public function stopConsume() {
			foreach (array_keys($this->channel->callbacks) as $key) {
				$this->channel->basic_cancel($key);
			}
		}

		/**
		 * Start consuming from Rabbit MQ.
		 *
		 * If the connection dies, this throws and the process is expected to
		 * exit and be restarted.
		 */
		public function consume() {
			$this->connect();
			$this->consuming = true;

			while ($this->channel->is_consuming()) {
				$this->channel->wait();
			}

			$this->channel->close();
			$this->connection->close();
		}

		/**
		 * Get RabbitMQ Channel
		 */
		public function getChannel() {
			$this->connect();

			return $this->channel;
		}
	}

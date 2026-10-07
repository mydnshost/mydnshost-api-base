<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;
	use PhpAmqpLib\Wire\AMQPTable;

	class EventQueue {
		// Instance of EventQueue.
		private static $instance = null;

		// Limits on subscriber queues, so a long-dead subscriber can't grow
		// one without bound or replay ancient events when it comes back.
		const QUEUE_MAX_AGE = 86400;
		const QUEUE_MAX_LENGTH = 100000;

		private $subscribers = [];
		private $actor = null;

		/**
		 * Get the EventQueue instance.
		 *
		 * @return EventQueue instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new EventQueue();
			}

			return self::$instance;
		}

		/**
		 * Set actor metadata to include with published events.
		 *
		 * @param $actor Array of actor info (type, email, key, etc.) or null
		 */
		public function setActor($actor) {
			$this->actor = $actor;
		}

		/**
		 * Get the current actor metadata.
		 *
		 * @return Actor array or null
		 */
		public function getActor() {
			return $this->actor;
		}

		/**
		 * Publish an event to the bus.
		 *
		 * @param $event Event name
		 * @param $args Event Arguments
		 */
		public function publish($event, $args) {
			$event = strtolower($event);

			try {
				$payload = ['event' => $event, 'args' => $args];
				if ($this->actor !== null) {
					$payload['actor'] = $this->actor;
				}
				$msg = new AMQPMessage(json_encode($payload), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
				RabbitMQ::get()->publish($msg, 'events', 'event.' . $event, function ($channel) {
					$this->declareExchange($channel);
				});
				return true;
			} catch (Exception $ex) {
				error_log('Failed to publish event ' . $event . ': ' . $ex->getMessage());
				return false;
			}
		}

		/**
		 * Declare the events exchange.
		 *
		 * @param $channel Channel to use
		 */
		private function declareExchange($channel) {
			$channel->exchange_declare('events', 'topic', false, true, false);
		}

		/**
		 * Subscribe to events of a certain type on the bus.
		 *
		 * @param $event Event name
		 * @param $function Function to call
		 */
		public function subscribe($event, $function) {
			$event = strtolower($event);

			if (!array_key_exists($event, $this->subscribers)) { $this->subscribers[$event] = []; }

			$this->subscribers[$event][] = $function;
		}

		public function handleSubscribers($event) {
			if (is_array($event) && isset($event['event'])) {
				if (array_key_exists($event['event'], $this->subscribers)) {
					$this->actor = $event['actor'] ?? null;
					foreach ($this->subscribers[$event['event']] as $callable) {
						try {
							call_user_func_array($callable, isset($event['args']) ? $event['args'] : []);
						} catch (Throwable $ex) {
							if ($event['event'] != 'subscriber.error') {
								EventQueue::get()->publish('subscriber.error', [$ex->getMessage(), $ex->getTraceAsString(), $event]);
							}
						}
					}
					$this->actor = null;
				}
			}
		}

		/**
		 * Allow consuming events from the bus.
		 *
		 * Each subscribing service has its own named durable queue, so events
		 * queue up while it isn't running. Multiple instances of one service
		 * share the queue. Events are acked once handled, so are redelivered
		 * if we die while handling one.
		 *
		 * @param $queueName Name of the queue for this service (eg
		 *                   'events.dispatcher').
		 * @param $function If this is given, this will be called instead of
		 *                  our own handling.
		 * @param $bindingKey Events to subscribe to.
		 */
		public function consumeEvents($queueName, $function = NULL, $bindingKey = '#') {
			$channel = RabbitMQ::get()->getChannel();

			$this->declareExchange($channel);
			$channel->queue_declare($queueName, false, true, false, false, false, new AMQPTable(['x-message-ttl' => self::QUEUE_MAX_AGE * 1000, 'x-max-length' => self::QUEUE_MAX_LENGTH]));
			$channel->queue_bind($queueName, 'events', $bindingKey);

			RabbitMQ::get()->consumeQueue($queueName, function($msg) use ($function) {
				$event = @json_decode($msg->body, true);
				if (json_last_error() != JSON_ERROR_NONE) { $event = $msg->body; }

				if ($function != null) {
					call_user_func_array($function, [$event]);
				} else {
					$this->handleSubscribers($event);
				}

				$msg->ack();
			}, 10);
		}
	}

<?php

	use PhpAmqpLib\Connection\AMQPStreamConnection;
	use PhpAmqpLib\Message\AMQPMessage;
	use PhpAmqpLib\Wire\AMQPTable;
  	use shanemcc\phpdb\DB;

	class JobQueue {
		// Instance of JobQueue.
		private static $instance = null;

		// Messages older than this are dropped from job queues. The sweeper
		// expires jobs at the same age rather than running them.
		const MAX_AGE = 86400;

		private $callbackQueue = null;
		private $callbackQueueChannel = null;
		private $callbackQueueResponses = [];

		/**
		 * Get the JobQueue instance.
		 *
		 * @return JobQueue instance.
		 */
		public static function get() {
			if (self::$instance == null) {
				self::$instance = new JobQueue();
			}

			return self::$instance;
		}

		/**
		 * Create a job, this will not publish it.
		 *
		 * @param $job Job name
		 * @param $args Job Arguments
		 * @param $reason Optional reason for creating the job
		 * @param $createdByJob Optional ID of the job that created this one
		 */
		public function create($jobname, $args, $reason = null, $createdByJob = null) {
			$jobname = strtolower($jobname);

			$job = new Job(DB::get());
			$job->setName($jobname)->setJobData($args)->setCreated(time())->setState('created');
			if ($reason !== null) {
				$job->setReason($reason);
			}
			if ($createdByJob !== null) {
				$job->setCreatedByJob($createdByJob);
			}
			$job->save();

			return $job;
		}

		/**
		 * Find an already created job.
		 *
		 * @param $job Job id
		 */
		public function find($job) {
			return Job::load(DB::get(), $job);
		}

		/**
		 * Declare the queue for a job.
		 *
		 * This is done by both publishers and consumers, so that jobs are
		 * stored even if no worker for them has started yet. The arguments
		 * must match everywhere, or RabbitMQ will refuse the declare.
		 *
		 * @param $channel Channel to use
		 * @param $jobname Job name
		 * @return [queue name, ready message count, consumer count]
		 */
		private function declareJobQueue($channel, $jobname) {
			$channel->exchange_declare('jobs', 'direct', false, true, false);
			$result = $channel->queue_declare('job.' . $jobname, false, true, false, false, false, new AMQPTable(['x-message-ttl' => self::MAX_AGE * 1000]));
			$channel->queue_bind('job.' . $jobname, 'jobs', 'job.' . $jobname);

			return $result;
		}

		/**
		 * Get the number of messages waiting in a job queue.
		 *
		 * @param $jobname Job name
		 * @return Number of messages that are ready to be consumed.
		 */
		public function getQueuedCount($jobname) {
			$jobname = strtolower($jobname);

			return RabbitMQ::get()->withChannel(function ($channel) use ($jobname) {
				return $this->declareJobQueue($channel, $jobname)[1];
			});
		}

		/**
		 * Publish a job.
		 *
		 * @param $job Job to publish
		 */
		public function publish($job) {
			$jobname = strtolower($job->getName());

			$msg = new AMQPMessage(json_encode(['job' => $jobname, 'args' => $job->getJobData(), 'jobid' => $job->getID()]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
			RabbitMQ::get()->publish($msg, 'jobs', 'job.' . $jobname, function ($channel) use ($jobname) {
				$this->declareJobQueue($channel, $jobname);
			});

			return $job->getID();
		}


		/**
		 * Publish a job and wait for the result.
		 *
		 * @param $job Job to publish
		 * @return Output from job.
		 */
		public function publishAndWait($job) {
			$jobname = strtolower($job->getName());

			$correlation_id = genUUID();
			$this->callbackQueueResponses[$correlation_id] = NULL;

			$buildMessage = function ($channel) use ($jobname, $job, $correlation_id) {
				// Our reply queue goes away with the connection, so redeclare it
				// if we've reconnected since.
				if ($this->callbackQueueChannel !== $channel) {
					list($this->callbackQueue, ,) = $channel->queue_declare("", false, false, true, false);
					$this->callbackQueueChannel = $channel;

					$channel->basic_consume($this->callbackQueue, '', false, true, false, false, function ($msg) {
						if ($msg->has('correlation_id') && array_key_exists($msg->get('correlation_id'), $this->callbackQueueResponses)) {
							$this->callbackQueueResponses[$msg->get('correlation_id')] = $msg->body === NULL ? '' : $msg->body;
						}
					});
				}

				return new AMQPMessage(json_encode(['job' => $jobname, 'args' => $job->getJobData(), 'jobid' => $job->getID()]), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, 'reply_to' => $this->callbackQueue, 'correlation_id' => $correlation_id]);
			};

			RabbitMQ::get()->publish($buildMessage, 'jobs', 'job.' . $jobname, function ($channel) use ($jobname) {
				$this->declareJobQueue($channel, $jobname);
			});

			// Wait for response.
			while ($this->callbackQueueResponses[$correlation_id] === null) { RabbitMQ::get()->getChannel()->wait(); }
			$response = $this->callbackQueueResponses[$correlation_id];
			unset($this->callbackQueueResponses[$correlation_id]);

			return [$job->getID(), $response];
		}

		/**
		 * Reply to a job (if the publisher is waiting for one) and ack it.
		 *
		 * @param $req Message we are replying to
		 * @param $response Response to send
		 */
		public function replytoJob($req, $response) {
			if ($req->has('correlation_id')) {
				$msg = new AMQPMessage($response, array('correlation_id' => $req->get('correlation_id')));
				RabbitMQ::get()->publish($msg, '', $req->get('reply_to'));
			}

			$req->ack();
		}

		/**
		 * Allow consuming jobs from the bus.
		 *
		 * @param $jobname Job name key
		 * @param $function Function call when we get a job
		 */
		public function consumeJobs($jobname, $function) {
			$jobname = strtolower($jobname);

			$this->declareJobQueue(RabbitMQ::get()->getChannel(), $jobname);
			RabbitMQ::get()->consumeQueue('job.' . $jobname, $function);
		}
	}

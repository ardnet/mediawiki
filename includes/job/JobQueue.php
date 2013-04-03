<?php
/**
 * Job queue base code.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @defgroup JobQueue JobQueue
 * @author Aaron Schulz
 */

/**
 * Class to handle enqueueing and running of background jobs
 *
 * @ingroup JobQueue
 * @since 1.21
 */
abstract class JobQueue {
	protected $wiki; // string; wiki ID
	protected $type; // string; job type
	protected $order; // string; job priority for pop()
	protected $claimTTL; // integer; seconds
	protected $maxTries; // integer; maximum number of times to try a job
	protected $checkDelay; // boolean; allow delayed jobs

	const QOS_ATOMIC = 1; // integer; "all-or-nothing" job insertions
	const QoS_Atomic = 1; // integer; "all-or-nothing" job insertions (b/c)

	const ROOTJOB_TTL = 2419200; // integer; seconds to remember root jobs (28 days)

	/**
	 * @param $params array
	 */
	protected function __construct( array $params ) {
		$this->wiki = $params['wiki'];
		$this->type = $params['type'];
		$this->claimTTL = isset( $params['claimTTL'] ) ? $params['claimTTL'] : 0;
		$this->maxTries = isset( $params['maxTries'] ) ? $params['maxTries'] : 3;
		if ( isset( $params['order'] ) && $params['order'] !== 'any' ) {
			$this->order = $params['order'];
		} else {
			$this->order = $this->optimalOrder();
		}
		if ( !in_array( $this->order, $this->supportedOrders() ) ) {
			throw new MWException( __CLASS__ . " does not support '{$this->order}' order." );
		}
		$this->checkDelay = !empty( $params['checkDelay'] );
		if ( $this->checkDelay && !$this->supportsDelayedJobs() ) {
			throw new MWException( __CLASS__ . " does not support delayed jobs." );
		}
	}

	/**
	 * Get a job queue object of the specified type.
	 * $params includes:
	 *   - class      : What job class to use (determines job type)
	 *   - wiki       : wiki ID of the wiki the jobs are for (defaults to current wiki)
	 *   - type       : The name of the job types this queue handles
	 *   - order      : Order that pop() selects jobs, one of "fifo", "timestamp" or "random".
	 *                  If "fifo" is used, the queue will effectively be FIFO. Note that job
	 *                  completion will not appear to be exactly FIFO if there are multiple
	 *                  job runners since jobs can take different times to finish once popped.
	 *                  If "timestamp" is used, the queue will at least be loosely ordered
	 *                  by timestamp, allowing for some jobs to be popped off out of order.
	 *                  If "random" is used, pop() will pick jobs in random order.
	 *                  Note that it may only be weakly random (e.g. a lottery of the oldest X).
	 *                  If "any" is choosen, the queue will use whatever order is the fastest.
	 *                  This might be useful for improving concurrency for job acquisition.
	 *   - claimTTL   : If supported, the queue will recycle jobs that have been popped
	 *                  but not acknowledged as completed after this many seconds. Recycling
	 *                  of jobs simple means re-inserting them into the queue. Jobs can be
	 *                  attempted up to three times before being discarded.
	 *   - checkDelay : If supported, respect Job::getReleaseTimestamp() in the push functions.
	 *                  This lets delayed jobs wait in a staging area until a given timestamp is
	 *                  reached, at which point they will enter the queue. If this is not enabled
	 *                  or not supported, an exception will be thrown on delayed job insertion.
	 *
	 * Queue classes should throw an exception if they do not support the options given.
	 *
	 * @param $params array
	 * @return JobQueue
	 * @throws MWException
	 */
	final public static function factory( array $params ) {
		$class = $params['class'];
		if ( !MWInit::classExists( $class ) ) {
			throw new MWException( "Invalid job queue class '$class'." );
		}
		$obj = new $class( $params );
		if ( !( $obj instanceof self ) ) {
			throw new MWException( "Class '$class' is not a " . __CLASS__ . " class." );
		}
		return $obj;
	}

	/**
	 * @return string Wiki ID
	 */
	final public function getWiki() {
		return $this->wiki;
	}

	/**
	 * @return string Job type that this queue handles
	 */
	final public function getType() {
		return $this->type;
	}

	/**
	 * @return string One of (random, timestamp, fifo)
	 */
	final public function getOrder() {
		return $this->order;
	}

	/**
	 * @return Array Subset of (random, timestamp, fifo)
	 */
	abstract protected function supportedOrders();

	/**
	 * @return string One of (random, timestamp, fifo)
	 */
	abstract protected function optimalOrder();

	/**
	 * @return boolean Whether delayed jobs are supported
	 */
	protected function supportsDelayedJobs() {
		return false; // not implemented
	}

	/**
	 * Quickly check if the queue has no available (unacquired, non-delayed) jobs.
	 * Queue classes should use caching if they are any slower without memcached.
	 *
	 * If caching is used, this might return false when there are actually no jobs.
	 * If pop() is called and returns false then it should correct the cache. Also,
	 * calling flushCaches() first prevents this. However, this affect is typically
	 * not distinguishable from the race condition between isEmpty() and pop().
	 *
	 * @return bool
	 * @throws MWException
	 */
	final public function isEmpty() {
		wfProfileIn( __METHOD__ );
		$res = $this->doIsEmpty();
		wfProfileOut( __METHOD__ );
		return $res;
	}

	/**
	 * @see JobQueue::isEmpty()
	 * @return bool
	 */
	abstract protected function doIsEmpty();

	/**
	 * Get the number of available (unacquired, non-delayed) jobs in the queue.
	 * Queue classes should use caching if they are any slower without memcached.
	 *
	 * If caching is used, this number might be out of date for a minute.
	 *
	 * @return integer
	 * @throws MWException
	 */
	final public function getSize() {
		wfProfileIn( __METHOD__ );
		$res = $this->doGetSize();
		wfProfileOut( __METHOD__ );
		return $res;
	}

	/**
	 * @see JobQueue::getSize()
	 * @return integer
	 */
	abstract protected function doGetSize();

	/**
	 * Get the number of acquired jobs (these are temporarily out of the queue).
	 * Queue classes should use caching if they are any slower without memcached.
	 *
	 * If caching is used, this number might be out of date for a minute.
	 *
	 * @return integer
	 * @throws MWException
	 */
	final public function getAcquiredCount() {
		wfProfileIn( __METHOD__ );
		$res = $this->doGetAcquiredCount();
		wfProfileOut( __METHOD__ );
		return $res;
	}

	/**
	 * @see JobQueue::getAcquiredCount()
	 * @return integer
	 */
	abstract protected function doGetAcquiredCount();

	/**
	 * Get the number of delayed jobs (these are temporarily out of the queue).
	 * Queue classes should use caching if they are any slower without memcached.
	 *
	 * If caching is used, this number might be out of date for a minute.
	 *
	 * @return integer
	 * @throws MWException
	 * @since 1.22
	 */
	final public function getDelayedCount() {
		wfProfileIn( __METHOD__ );
		$res = $this->doGetDelayedCount();
		wfProfileOut( __METHOD__ );
		return $res;
	}

	/**
	 * @see JobQueue::getDelayedCount()
	 * @return integer
	 */
	protected function doGetDelayedCount() {
		return 0; // not implemented
	}

	/**
	 * Get the number of acquired jobs that can no longer be attempted.
	 * Queue classes should use caching if they are any slower without memcached.
	 *
	 * If caching is used, this number might be out of date for a minute.
	 *
	 * @return integer
	 * @throws MWException
	 */
	final public function getAbandonedCount() {
		wfProfileIn( __METHOD__ );
		$res = $this->doGetAbandonedCount();
		wfProfileOut( __METHOD__ );
		return $res;
	}

	/**
	 * @see JobQueue::getAbandonedCount()
	 * @return integer
	 */
	protected function doGetAbandonedCount() {
		return 0; // not implemented
	}

	/**
	 * Push a single jobs into the queue.
	 * This does not require $wgJobClasses to be set for the given job type.
	 * Outside callers should use JobQueueGroup::push() instead of this function.
	 *
	 * @param $jobs Job|Array
	 * @param $flags integer Bitfield (supports JobQueue::QOS_ATOMIC)
	 * @return bool Returns false on failure
	 * @throws MWException
	 */
	final public function push( $jobs, $flags = 0 ) {
		return $this->batchPush( is_array( $jobs ) ? $jobs : array( $jobs ), $flags );
	}

	/**
	 * Push a batch of jobs into the queue.
	 * This does not require $wgJobClasses to be set for the given job type.
	 * Outside callers should use JobQueueGroup::push() instead of this function.
	 *
	 * @param array $jobs List of Jobs
	 * @param $flags integer Bitfield (supports JobQueue::QOS_ATOMIC)
	 * @return bool Returns false on failure
	 * @throws MWException
	 */
	final public function batchPush( array $jobs, $flags = 0 ) {
		if ( !count( $jobs ) ) {
			return true; // nothing to do
		}

		foreach ( $jobs as $job ) {
			if ( $job->getType() !== $this->type ) {
				throw new MWException(
					"Got '{$job->getType()}' job; expected a '{$this->type}' job." );
			} elseif ( $job->getReleaseTimestamp() && !$this->checkDelay ) {
				throw new MWException(
					"Got delayed '{$job->getType()}' job; delays are not supported." );
			}
		}

		wfProfileIn( __METHOD__ );
		$ok = $this->doBatchPush( $jobs, $flags );
		wfProfileOut( __METHOD__ );
		return $ok;
	}

	/**
	 * @see JobQueue::batchPush()
	 * @return bool
	 */
	abstract protected function doBatchPush( array $jobs, $flags );

	/**
	 * Pop a job off of the queue.
	 * This requires $wgJobClasses to be set for the given job type.
	 * Outside callers should use JobQueueGroup::pop() instead of this function.
	 *
	 * @return Job|bool Returns false if there are no jobs
	 * @throws MWException
	 */
	final public function pop() {
		global $wgJobClasses;

		if ( $this->wiki !== wfWikiID() ) {
			throw new MWException( "Cannot pop '{$this->type}' job off foreign wiki queue." );
		} elseif ( !isset( $wgJobClasses[$this->type] ) ) {
			// Do not pop jobs if there is no class for the queue type
			throw new MWException( "Unrecognized job type '{$this->type}'." );
		}

		wfProfileIn( __METHOD__ );
		$job = $this->doPop();
		wfProfileOut( __METHOD__ );

		// Flag this job as an old duplicate based on its "root" job...
		try {
			if ( $job && $this->isRootJobOldDuplicate( $job ) ) {
				wfIncrStats( 'job-pop-duplicate' );
				$job = DuplicateJob::newFromJob( $job ); // convert to a no-op
			}
		} catch ( MWException $e ) {} // don't lose jobs over this

		return $job;
	}

	/**
	 * @see JobQueue::pop()
	 * @return Job
	 */
	abstract protected function doPop();

	/**
	 * Acknowledge that a job was completed.
	 *
	 * This does nothing for certain queue classes or if "claimTTL" is not set.
	 * Outside callers should use JobQueueGroup::ack() instead of this function.
	 *
	 * @param $job Job
	 * @return bool
	 * @throws MWException
	 */
	final public function ack( Job $job ) {
		if ( $job->getType() !== $this->type ) {
			throw new MWException( "Got '{$job->getType()}' job; expected '{$this->type}'." );
		}
		wfProfileIn( __METHOD__ );
		$ok = $this->doAck( $job );
		wfProfileOut( __METHOD__ );
		return $ok;
	}

	/**
	 * @see JobQueue::ack()
	 * @return bool
	 */
	abstract protected function doAck( Job $job );

	/**
	 * Register the "root job" of a given job into the queue for de-duplication.
	 * This should only be called right *after* all the new jobs have been inserted.
	 * This is used to turn older, duplicate, job entries into no-ops. The root job
	 * information will remain in the registry until it simply falls out of cache.
	 *
	 * This requires that $job has two special fields in the "params" array:
	 *   - rootJobSignature : hash (e.g. SHA1) that identifies the task
	 *   - rootJobTimestamp : TS_MW timestamp of this instance of the task
	 *
	 * A "root job" is a conceptual job that consist of potentially many smaller jobs
	 * that are actually inserted into the queue. For example, "refreshLinks" jobs are
	 * spawned when a template is edited. One can think of the task as "update links
	 * of pages that use template X" and an instance of that task as a "root job".
	 * However, what actually goes into the queue are potentially many refreshLinks2 jobs.
	 * Since these jobs include things like page ID ranges and DB master positions, and morph
	 * into smaller refreshLinks2 jobs recursively, simple duplicate detection (like job_sha1)
	 * for individual jobs being identical is not useful.
	 *
	 * In the case of "refreshLinks", if these jobs are still in the queue when the template
	 * is edited again, we want all of these old refreshLinks jobs for that template to become
	 * no-ops. This can greatly reduce server load, since refreshLinks jobs involves parsing.
	 * Essentially, the new batch of jobs belong to a new "root job" and the older ones to a
	 * previous "root job" for the same task of "update links of pages that use template X".
	 *
	 * This does nothing for certain queue classes.
	 *
	 * @param $job Job
	 * @return bool
	 * @throws MWException
	 */
	final public function deduplicateRootJob( Job $job ) {
		if ( $job->getType() !== $this->type ) {
			throw new MWException( "Got '{$job->getType()}' job; expected '{$this->type}'." );
		}
		wfProfileIn( __METHOD__ );
		$ok = $this->doDeduplicateRootJob( $job );
		wfProfileOut( __METHOD__ );
		return $ok;
	}

	/**
	 * @see JobQueue::deduplicateRootJob()
	 * @param $job Job
	 * @return bool
	 */
	protected function doDeduplicateRootJob( Job $job ) {
		global $wgMemc;

		$params = $job->getParams();
		if ( !isset( $params['rootJobSignature'] ) ) {
			throw new MWException( "Cannot register root job; missing 'rootJobSignature'." );
		} elseif ( !isset( $params['rootJobTimestamp'] ) ) {
			throw new MWException( "Cannot register root job; missing 'rootJobTimestamp'." );
		}
		$key = $this->getRootJobCacheKey( $params['rootJobSignature'] );
		// Callers should call batchInsert() and then this function so that if the insert
		// fails, the de-duplication registration will be aborted. Since the insert is
		// deferred till "transaction idle", do the same here, so that the ordering is
		// maintained. Having only the de-duplication registration succeed would cause
		// jobs to become no-ops without any actual jobs that made them redundant.
		$timestamp = $wgMemc->get( $key ); // current last timestamp of this job
		if ( $timestamp && $timestamp >= $params['rootJobTimestamp'] ) {
			return true; // a newer version of this root job was enqueued
		}

		// Update the timestamp of the last root job started at the location...
		return $wgMemc->set( $key, $params['rootJobTimestamp'], JobQueueDB::ROOTJOB_TTL );
	}

	/**
	 * Check if the "root" job of a given job has been superseded by a newer one
	 *
	 * @param $job Job
	 * @return bool
	 * @throws MWException
	 */
	final protected function isRootJobOldDuplicate( Job $job ) {
		if ( $job->getType() !== $this->type ) {
			throw new MWException( "Got '{$job->getType()}' job; expected '{$this->type}'." );
		}
		wfProfileIn( __METHOD__ );
		$isDuplicate = $this->doIsRootJobOldDuplicate( $job );
		wfProfileOut( __METHOD__ );
		return $isDuplicate;
	}

	/**
	 * @see JobQueue::isRootJobOldDuplicate()
	 * @param Job $job
	 * @return bool
	 */
	protected function doIsRootJobOldDuplicate( Job $job ) {
		global $wgMemc;

		$params = $job->getParams();
		if ( !isset( $params['rootJobSignature'] ) ) {
			return false; // job has no de-deplication info
		} elseif ( !isset( $params['rootJobTimestamp'] ) ) {
			trigger_error( "Cannot check root job; missing 'rootJobTimestamp'." );
			return false;
		}

		// Get the last time this root job was enqueued
		$timestamp = $wgMemc->get( $this->getRootJobCacheKey( $params['rootJobSignature'] ) );

		// Check if a new root job was started at the location after this one's...
		return ( $timestamp && $timestamp > $params['rootJobTimestamp'] );
	}

	/**
	 * @param string $signature Hash identifier of the root job
	 * @return string
	 */
	protected function getRootJobCacheKey( $signature ) {
		list( $db, $prefix ) = wfSplitWikiID( $this->wiki );
		return wfForeignMemcKey( $db, $prefix, 'jobqueue', $this->type, 'rootjob', $signature );
	}

	/**
	 * Wait for any slaves or backup servers to catch up.
	 *
	 * This does nothing for certain queue classes.
	 *
	 * @return void
	 * @throws MWException
	 */
	final public function waitForBackups() {
		wfProfileIn( __METHOD__ );
		$this->doWaitForBackups();
		wfProfileOut( __METHOD__ );
	}

	/**
	 * @see JobQueue::waitForBackups()
	 * @return void
	 */
	protected function doWaitForBackups() {}

	/**
	 * Return a map of task names to task definition maps.
	 * A "task" is a fast periodic queue maintenance action.
	 * Mutually exclusive tasks must implement their own locking in the callback.
	 *
	 * Each task value is an associative array with:
	 *   - name     : the name of the task
	 *   - callback : a PHP callable that performs the task
	 *   - period   : the period in seconds corresponding to the task frequency
	 *
	 * @return Array
	 */
	final public function getPeriodicTasks() {
		$tasks = $this->doGetPeriodicTasks();
		foreach ( $tasks as $name => &$def ) {
			$def['name'] = $name;
		}
		return $tasks;
	}

	/**
	 * @see JobQueue::getPeriodicTasks()
	 * @return Array
	 */
	protected function doGetPeriodicTasks() {
		return array();
	}

	/**
	 * Clear any process and persistent caches
	 *
	 * @return void
	 */
	final public function flushCaches() {
		wfProfileIn( __METHOD__ );
		$this->doFlushCaches();
		wfProfileOut( __METHOD__ );
	}

	/**
	 * @see JobQueue::flushCaches()
	 * @return void
	 */
	protected function doFlushCaches() {}

	/**
	 * Get an iterator to traverse over all available jobs in this queue.
	 * This does not include jobs that are currently acquired or delayed.
	 * This should only be called on a queue that is no longer being popped.
	 *
	 * @return Iterator
	 * @throws MWException
	 */
	abstract public function getAllQueuedJobs();

	/**
	 * Get an iterator to traverse over all delayed jobs in this queue.
	 * This should only be called on a queue that is no longer being popped.
	 *
	 * @return Iterator
	 * @throws MWException
	 * @since 1.22
	 */
	public function getAllDelayedJobs() {
		return new ArrayIterator( array() ); // not implemented
	}

	/**
	 * Namespace the queue with a key to isolate it for testing
	 *
	 * @param $key string
	 * @return void
	 * @throws MWException
	 */
	public function setTestingPrefix( $key ) {
		throw new MWException( "Queue namespacing not supported for this queue type." );
	}
}

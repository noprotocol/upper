<?php namespace ostark\upper\jobs;

use Craft;
use craft\queue\BaseJob;
use ostark\upper\Plugin;

/**
 * Class PurgeCache
 *
 * @package ostark\upper\jobs
 */
class PurgeCacheJob extends BaseJob
{
    /**
     * A purge can be refused when the CDN's rate limit is exhausted — Akamai allows
     * 500 tag objects per minute. Tokens refill, so a refused purge is requeued
     * instead of being dropped: a lost purge leaves the page stale until its own
     * max-age expires.
     */
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY = 30; // seconds

    /**
     * @var string tag
     */
    public $tag;

    /**
     * @var int the attempt this job is on, 1 for the first one
     */
    public $attempt = 1;

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if (!$this->tag) {
            return;
        }

        // Get registered purger
        $purger = Plugin::getInstance()->getPurger();

        if ($purger->purgeTag($this->tag)) {
            return;
        }

        if ($this->attempt >= self::MAX_ATTEMPTS) {
            Craft::warning("Gave up purging '{$this->tag}' after {$this->attempt} attempts.", 'upper');

            return;
        }

        Craft::warning("Requeued purging '{$this->tag}' after attempt {$this->attempt} failed.", 'upper');

        // Craft doesn't retry a job that returned without throwing, so delay a copy
        // of this job instead of sleeping in the worker
        Craft::$app->getQueue()
            ->delay(self::RETRY_DELAY)
            ->push(new self([
                'tag' => $this->tag,
                'attempt' => $this->attempt + 1,
            ]));
    }


    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return Craft::t('upper', 'Upper Purge: {tag}', ['tag' => $this->tag]);
    }
}

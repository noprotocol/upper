<?php

namespace ostark\upper;

use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\events\PopulateElementEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\SectionEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\services\Entries;
use craft\services\Structures;
use craft\utilities\ClearCaches;
use craft\web\Response;
use craft\web\View;
use ostark\upper\behaviors\CacheControlBehavior;
use ostark\upper\behaviors\TagHeaderBehavior;
use ostark\upper\events\CacheResponseEvent;
use ostark\upper\events\PurgeEvent;
use ostark\upper\jobs\PurgeCacheJob;
use yii\base\Event;

/**
 * Class EventRegistrar
 *
 * @package ostark\upper
 */
class EventRegistrar
{
    /**
     * Tags already queued in this process, with the time they were queued.
     *
     * Craft fires a save event for every site an element is propagated to, and a job
     * that saves many elements (a bulk status change, an import) fires one per
     * element, so the same tag would be queued over and over: a single entry change
     * turned into 13 purge jobs for 4 distinct tags. Every tag counts towards the
     * CDN's purge rate limit, so repeated tags are skipped here.
     *
     * @var array<string, int>
     */
    private static array $queuedTags = [];

    /**
     * How long a queued tag suppresses duplicates, in seconds. Long enough to
     * collapse the events of one change, short enough that a later change purges
     * the tag again — a page can be re-cached between two changes.
     */
    private const TAG_DEDUPE_WINDOW = 2;

    public static function registerUpdateEvents()
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, function ($event) {
            static::handleUpdateEvent($event);
        });
        Event::on( Entries::class, Entries::EVENT_AFTER_SAVE_SECTION, function ($event) {
            static::handleUpdateEvent($event);
        });
    }

    public static function registerFrontendEvents()
    {
        // No need to continue when in cli mode
        if (\Craft::$app instanceof \craft\console\Application) {
            return false;
        }

        // HTTP request object
        $request = \Craft::$app->getRequest();

        // Don't cache CP, LivePreview, Action, Non-GET requests
        if (
            $request->getIsCpRequest() ||
            $request->getIsLivePreview() ||
            $request->getIsActionRequest() ||
            !$request->getIsGet()
        ) {
            /** @var CacheControlBehavior|TagHeaderBehavior|Response  $response */
            $response = \Craft::$app->getResponse();
            $response->addCacheControlDirective('private');
            $response->addCacheControlDirective('no-cache');

            return false;
        }

        // Collect tags
        Event::on(ElementQuery::class, ElementQuery::EVENT_AFTER_POPULATE_ELEMENT, function (PopulateElementEvent $event) {

            // Don't collect MatrixBlock and User elements for now
            if (!Plugin::getInstance()->getSettings()->isCachableElement(get_class($event->element))) {
                return;
            }

            // Tag with GlobalSet handle
            if ($event->element instanceof \craft\elements\GlobalSet) {
                Plugin::getInstance()->getTagCollection()->add($event->element->handle);
            }

            // Add to collection
            Plugin::getInstance()->getTagCollection()->addTagsFromElement($event->row);
        });

        // Add the tags to the response header
        Event::on(View::class, View::EVENT_AFTER_RENDER_PAGE_TEMPLATE, function (TemplateEvent $event) {

            /** @var \yii\web\Response|CacheControlBehavior|TagHeaderBehavior $response */
            $response      = \Craft::$app->getResponse();
            $plugin        = Plugin::getInstance();
            $tagCollection = $plugin->getTagCollection();
            $tags          = $plugin->getTagCollection()->getAll();
            $settings      = $plugin->getSettings();
            $headers       = $response->getHeaders();

            // Make existing cache-control headers accessible
            $response->setCacheControlDirectiveFromString($headers->get('cache-control'));

            // Don't cache if private | no-cache set already
            if ($response->hasCacheControlDirective('private') || $response->hasCacheControlDirective('no-cache')) {
                $headers->set(Plugin::INFO_HEADER_NAME, 'BYPASS');

                return;
            }

            // MaxAge or defaultMaxAge?
            $maxAge = $response->getMaxAge() ?? $settings->defaultMaxAge;

            // Set Headers
            $maxBytes = $settings->maxBytesForCacheTagHeader;
            $maxedTags = $tagCollection->getUntilMaxBytes($maxBytes);
            $response->setTagHeader($settings->getTagHeaderName(), $maxedTags, $settings->getHeaderTagDelimiter());

            // Flag truncation
            if (count($tags) > count($maxedTags)) {
                $headers->set(Plugin::TRUNCATED_HEADER_NAME, (string) (count($tags) - count($maxedTags)));
            }

            $response->setSharedMaxAge($maxAge);
            $headers->set(Plugin::INFO_HEADER_NAME, "CACHED: " . date(\DateTime::ISO8601));

            $plugin->trigger($plugin::EVENT_AFTER_SET_TAG_HEADER, new CacheResponseEvent(
                [
                    'tags'       => $tags,
                    'maxAge'     => $maxAge,
                    'requestUrl' => \Craft::$app->getRequest()->getUrl(),
                    'headers'    => $response->getHeaders()->toArray()
                ]
            ));
        });
    }


    public static function registerCpEvents()
    {
        // Register cache purge checkbox
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_CACHE_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $driver           = ucfirst(Plugin::getInstance()->getSettings()->driver);
                $event->options[] = [
                    'key'    => 'upper-purge-all',
                    'label'  => \Craft::t('upper', 'Upper ({driver})', ['driver' => $driver]),
                    'action' => function () {
                        Plugin::getInstance()->getPurger()->purgeAll();
                    },
                ];
            }
        );
    }


    public static function registerFallback()
    {

        Event::on(Plugin::class, Plugin::EVENT_AFTER_SET_TAG_HEADER, function (CacheResponseEvent $event) {

            // not tagged?
            if (0 == count($event->tags)) {
                return;
            }

            // fulltext or array
            $tags = \Craft::$app->getDb()->getIsMysql()
                ? implode(" ", $event->tags)
                : str_replace(['[', ']'], ['{', '}'], json_encode($event->tags) ?: '[]');

            // in order to have a unique (collitions are possible) identifier by url with a fixed length
            $urlHash = md5($event->requestUrl);

            try {
                // Insert item
                \Craft::$app->getDb()->createCommand()
                    ->upsert(
                        // Table
                        Plugin::CACHE_TABLE,

                        // Identifier
                        ['urlHash' => $urlHash],

                        // Data
                        [
                            'urlHash' => $urlHash,
                            'url'     => $event->requestUrl,
                            'tags'    => $tags,
                            'headers' => json_encode($event->headers),
                            'siteId'  => \Craft::$app->getSites()->currentSite->id
                        ]
                    )
                    ->execute();
            } catch (\Exception $e) {
                \Craft::warning("Failed to register fallback.", "upper");
            }
        });
    }


    /**
     * @param \yii\base\Event $event
     */
    protected static function handleUpdateEvent(Event $event)
    {
        $tags = [];


        if ($event instanceof ElementEvent) {

            // Prevent purge on updates of drafts or revisions
            if (ElementHelper::isDraftOrRevision($event->element)) {
                return;
            }

            // Prevent purge on resaving
            if (property_exists($event->element, 'resaving') && $event->element->resaving === true) {
                return;
            }

            // For nested elements (blocks), purge the root owner's tags
            $rootOwner = $event->element->getRootOwner();
            if ($rootOwner !== $event->element) {
                if (isset($rootOwner->sectionId)) {
                    $tags[] = Plugin::TAG_PREFIX_SECTION . $rootOwner->sectionId;
                }
                $tags[] = Plugin::TAG_PREFIX_ELEMENT . $rootOwner->getId();
            }

            if (Plugin::getInstance()->getSettings()->isCachableElement(get_class($event->element))) {
                if ($event->element instanceof \craft\elements\GlobalSet && is_string($event->element->handle)) {
                    $tags[] = $event->element->handle;
                } elseif ($event->element instanceof \craft\elements\Asset && $event->isNew) {
                    $tags[] = (string)$event->element->volumeId;
                } else {
                    if (isset($event->element->sectionId)) {
                        $tags[] = Plugin::TAG_PREFIX_SECTION . $event->element->sectionId;
                    }
                    if (!$event->isNew) {
                        $tags[] = Plugin::TAG_PREFIX_ELEMENT . $event->element->getId();
                    }
                }
            }
        }

        if ($event instanceof SectionEvent) {
            $tags[] = Plugin::TAG_PREFIX_SECTION . $event->section->id;
        }

        if ($event instanceof MoveElementEvent) {
            $tags[] = Plugin::TAG_PREFIX_STRUCTURE . $event->structureId;
        }

        // Pages that list entries of a section (overviews) live in another section.
        // Such a page is tagged with the section it lists, and a CDN is free to
        // drop tags, so the section tag of the listing page is purged as well.
        $tags = array_unique(array_merge($tags, static::resolveRelatedTags($event)));

        if (count($tags) > 0) {
            static::purgeTags($tags);
        }
    }

    /**
     * Queue a purge job for each given tag.
     *
     * @param string[] $tags
     */
    public static function purgeTags(array $tags): void
    {
        $now = time();

        foreach ($tags as $tag) {
            $tag = Plugin::getInstance()->getTagCollection()->prepareTag($tag);

            // Skip tags queued moments ago (see $queuedTags)
            if (isset(self::$queuedTags[$tag]) && self::$queuedTags[$tag] > $now - self::TAG_DEDUPE_WINDOW) {
                continue;
            }

            self::$queuedTags[$tag] = $now;

            $purgeEvent = new PurgeEvent([
                'tag' => $tag,
            ]);

            Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_PURGE, $purgeEvent);

            // Push to queue
            \Craft::$app->getQueue()->ttr(1200)->push(new PurgeCacheJob(
                [
                    'tag' => $purgeEvent->tag
                ]
            ));

            Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_PURGE, $purgeEvent);
        }

        if (count(self::$queuedTags) > 1000) {
            self::$queuedTags = array_filter(
                self::$queuedTags,
                fn(int $queuedAt) => $queuedAt > $now - self::TAG_DEDUPE_WINDOW
            );
        }
    }

    /**
     * Tags to purge on top of the changed element's own tags.
     *
     * @param \yii\base\Event $event
     *
     * @return string[]
     */
    protected static function resolveRelatedTags(Event $event): array
    {
        $settings = Plugin::getInstance()->getSettings();

        if (!$event instanceof ElementEvent || !$settings->relatedSections) {
            return [];
        }

        // Blocks are saved on behalf of the entry holding them
        $element = $event->element;
        $rootOwner = $element->getRootOwner();
        if ($rootOwner !== null && $rootOwner !== $element) {
            $element = $rootOwner;
        }

        if (!$element instanceof Entry || $element->section === null) {
            return [];
        }

        $tags = [];

        foreach ($settings->relatedSections[$element->section->handle] ?? [] as $relatedHandle) {
            if ($relatedSection = \Craft::$app->getEntries()->getSectionByHandle($relatedHandle)) {
                $tags[] = Plugin::TAG_PREFIX_SECTION . $relatedSection->id;
            }
        }

        return $tags;
    }
}

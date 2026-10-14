<img src="https://github.com/ostark/upper/blob/master/resources/upper.png" height="80px"/>


# The pep pill for your Craft site

[![Latest Stable Version](https://poser.pugx.org/ostark/upper/v/stable)](https://packagist.org/packages/ostark/upper)
[![Total Downloads](https://poser.pugx.org/ostark/upper/downloads)](https://packagist.org/packages/ostark/upper)
[![Monthly Downloads](https://poser.pugx.org/ostark/upper/d/monthly)](https://packagist.org/packages/ostark/upper)



Upper speeds up Craft dramatically using a **Cache Proxy** in front of your webserver. 

The Plugin adds the neccessary `Cache-Control` and `XKEY/Surrogate-Key/Cache-Tag` headers to your pages. 
When Entries or Sections get updated in the Control Panel it takes care of the cache invalidation. 

If you need an introduction to HTTP Caching, I highly recommend [this article](https://blog.fortrabbit.com/mastering-http-caching). 

## Sponsor

Development happens in my free time, but also during working hours. 
Thanks [fortrabbit.com](https://www.fortrabbit.com/craft-hosting)!

If you are looking additional integrations (Cache Drivers), feel free to contribute or [raise an issue](https://github.com/ostark/upper/issues).

## Supported Cache Drivers

* [KeyCDN](https://www.keycdn.com) (CDN/SaaS)
* [Fastly](https://www.fastly.com) (CDN/SaaS)
* [Cloudflare](https://www.cloudflare.com) (CDN/SaaS)
* [Akamai](https://www.akamai.com) (CDN/SaaS)
* Varnish with XKEY support (your own proxy)
* Dummy (does nothing)

## Installation

1. Install with Composer via `composer require ostark/upper` from your project directory
2. Install plugin with this command `php craft plugin/install upper` or in the Craft CP under Settings > Plugins
3. A new configuration file gets generated automatically in `your-project/config/upper.php`.



### Fastly Setup

```
UPPER_DRIVER=fastly
FASTLY_API_TOKEN=<REPLACE-ME>
FASTLY_SERVICE_ID=<REPLACE-ME>
FASTLY_DOMAIN=http://<REPLACE-ME>
```

### KeyCDN Setup

```
UPPER_DRIVER=keycdn
KEYCDN_API_KEY=<REPLACE-ME>
KEYCDN_ZONE_URL=<REPLACE-ME>.kxcdn.com
KEYCDN_ZONE_ID=<REPLACE-ME>
```

### Cloudflare Setup

```
UPPER_DRIVER=cloudflare
CLOUDFLARE_API_TOKEN=<REPLACE-ME>
CLOUDFLARE_ZONE_ID=<REPLACE-ME>
CLOUDFLARE_DOMAIN=https://<REPLACE-ME>
```

By default, Cloudflare's CDN  does not cache HTML content. You need to create a [**Cache Level: Cache Everything**](https://support.cloudflare.com/hc/en-us/articles/202775670-How-Do-I-Tell-Cloudflare-What-to-Cache-) Page Rule to enable caching for "pages".

If you don't use Cloudflare Enterprise with native `Cache-Tag` support, make sure to enable `useLocalTags` in your `config/upper.php` file (default), otherwise disable it.

You can generate a token in the Cloudflare dashboard. You want to create a custom token with the "Zone.Cache Purge" permission that is restricted to the DNS zone(s) you wish to clear Cloudflare's cache for.

 
### Varnish Setup
Varnish URL supports multiple servers, separate with comma. E.g `http://1.1.1.1,http://2.2.2.2`
```
UPPER_DRIVER=varnish
VARNISH_URL=<REPLACE-ME>
```

### Akamai Setup

```
UPPER_DRIVER=akamai
AKAMAI_HOST=<REPLACE-ME>
AKAMAI_CLIENT_TOKEN=<REPLACE-ME>
AKAMAI_CLIENT_SECRET=<REPLACE-ME>
AKAMAI_ACCESS_TOKEN=<REPLACE-ME>
AKAMAI_MAX_SIZE=2048
```

Akamai expects the cache tags to be delimited by a comma (a space is not a valid
delimiter and not valid inside a tag). Set it in your config:

```php
'drivers' => [
    'akamai' => [
        'tagHeaderName'      => 'Edge-Cache-Tag',
        'tagHeaderDelimiter' => ',',
        // …
    ],
],
```

`AKAMAI_MAX_SIZE` is the number of body bytes EdgeGrid uses to sign a purge
request. Leave it empty to use EdgeGrid's default, or make sure it is at least as
large as your biggest purge body: anything beyond it is not part of the signature
and Akamai answers with `403`. Upper chunks purge requests to stay within that
size (and within Akamai's own 50 KB body limit).

### Purging overview pages

Pages that list entries of another section (overviews) are tagged with the section
they list. A CDN is free to drop tags — Akamai ignores anything past 128 tags or
8 KB per object — and then the overview silently stops being purged when one of the
entries it lists changes.

Tell Upper which pages depend on which section:

```php
// Key:   section handle of the changed entry
// Value: section handles of the pages listing those entries
'relatedSections' => [
    'disruptionDetail' => ['disruptionOverview'],
],
```

When an entry of `disruptionDetail` is saved or deleted, Upper purges the
`disruptionOverview` section tag as well. Matrix blocks are resolved to the entry
holding them, so editing an update block also refreshes the overview.

#### Why this purges by tag and not by url

Cache tags are scoped to the whole Akamai account, so one tag purge clears every
cached object carrying that tag — across all properties, hostnames and languages.
A multi-site setup whose overviews live on several domains therefore needs exactly
one purge request per network.

Url purging is the opposite: the hostname is part of the cache key, so a url has to
match the cached object exactly (scheme and hostname included), Fast Purge supports
no wildcards, and a relative url prefixed with `DEFAULT_SITE_URL` could only ever
purge objects on that single hostname. Upper therefore purges pages by tag only;
the driver skips relative urls with a warning if anything ever hands it one.

### Tuning

In a template, you can fully disable caching like so:

```
{% do upper.cache.never() %}
```

…which is a shorthand for:

```
{% header "Cache-Control: private, no-cache" %}
```

By default the `defaultMaxAge` config setting will be used to determine the cache duration to set. This can be overridden in a template:

```
{% do upper.cache.for('5 minutes') %}
```

…which is shorthand for:

```
{% header "Cache-Control: public, s-maxage=300" %}
```

### Performance results
![example](https://github.com/ostark/upper/blob/master/resources/preformance.png)

### Cache Tag Headers
![example](https://github.com/ostark/upper/blob/master/resources/response-header.png)


## Disclaimer

Even if the name of the plugin and some wordings are intentional, the author does not glorify any drug abuse. 🍻
The plugin is inspired by the [joshangell/Falcon](https://github.com/joshangell/Falcon).

---
name: Feature request
about: Something the package doesn't do that you wish it did.
title: ''
labels: enhancement
assignees: ''
---

## Use case

Describe the workload. What kind of Laravel app, which Redis or
Valkey topology, what does the calling code want to do.

## Current workaround

What do you do today to get around the missing feature? If there is
no workaround, say so.

## Proposed API

If you have a sketch of how the feature would look from the consumer
side, drop it here. If not, leave this blank and the maintainer will
start the design discussion.

```php
// Optional pseudocode
Cache::store('resp3:cluster')->many($keys);
```

## Why this should live here

Cluster, Sentinel, Pub/Sub, and connection pooling are all on the
roadmap. Other features should land here only if they fit the
"sync Redis client + Laravel cache driver" niche this package
covers. If the proposal could just as well live in a separate
package, say why it should not.

## Anything else

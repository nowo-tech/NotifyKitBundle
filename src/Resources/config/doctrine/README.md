# Doctrine XML mapping

The bundle-owned tables are mapped here in XML (contract §5: plain domain classes, referenced by id). The bundle
prepends this mapping on `doctrine.entity_manager` while `doctrine.auto_mapping` is true; a DDD host registers this
directory on its own entity manager (`type: xml`, `prefix: Nowo\NotifyKitBundle\Domain`). With `doctrine.mapping:
attribute` the bundle maps the attribute twins of `src/Infrastructure/Doctrine/Entity` instead (same schema, tested).

| File | Table (default prefix) |
| --- | --- |
| `Notification.orm.xml` | `nowo_notify_notification` (the inbox) |
| `DedupeMarker.orm.xml` | `nowo_notify_dedupe` (dedupe keys, 24 hours) |

The bundle writes through DBAL only: Doctrine never manages these classes (they are mapped read-only).

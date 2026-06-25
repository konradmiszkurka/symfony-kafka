# Architecture

A modular monolith (hexagonal + light DDD), event-driven via Kafka, with a CQRS-lite
command/query split and a transactional outbox for reliable publishing.

This document also answers a common question: **with Kafka, is hexagonal a "better"
architecture than CQRS?** Short answer: that's a false choice — they are different axes
and the best practice is to combine them (which is what this project does).

## Hexagonal vs CQRS — two different axes

They are **not** competing alternatives.

| | Hexagonal (ports & adapters) | CQRS |
|---|---|---|
| Axis | dependencies / boundaries | operation model |
| Question it answers | "how do I isolate the domain from the outside world?" | "do writes and reads have separate paths/models?" |
| What it gives | domain knows nothing about Doctrine/Kafka/HTTP; swappable adapters; testability | separate command/query; optionally separate read models |
| Scope | the whole application | the application layer (use cases) |

Hexagonal decides *where* code lives and *which way* dependencies point. CQRS decides *how*
you split operations into writes and reads. You can have hexagonal without CQRS, CQRS
without hexagonal, or — ideally — **both at once**.

## How each relates to Kafka

Kafka is the **integration backbone between modules / bounded contexts** (the event bus).

- **Hexagonal + Kafka** — Kafka is an *adapter* behind a port. The domain publishes an event
  through a port (`EventBusInterface`); whether the adapter is Kafka, an in-memory transport
  in tests, or something else is an infrastructure detail. This is essentially **mandatory**
  with Kafka — otherwise `php-rdkafka` leaks into the whole codebase.
- **CQRS + Kafka** — a natural fit, because **writes emit events**, and events are ideal for
  building **read models (projections)**: a consumer reads an event from Kafka and updates a
  denormalized view optimized for queries. That is "full" CQRS (a separate read store).

## Best-practice default (what this project does)

> **Hexagonal (always) + light CQRS (command/query buses, single data model) +
> event-driven over Kafka, with an outbox for reliability.**

Concretely in this codebase:

- **Hexagonal** — `Domain` / `Application` / `Infrastructure` / `UI` layers, with ports
  (`CourseRepository`, `EventBusInterface`, `CourseAvailabilityChecker`, ...) and adapters
  (Doctrine, the custom Kafka transport, Symfony Mailer).
- **CQRS-lite** — separate `command.bus` and `query.bus`, separate command/query objects, but
  a **single shared database** (no separate read store yet).
- **Event-driven** — integration events (`UserEnrolled`, `LessonCompleted`, `CourseCompleted`)
  flow through Kafka; consumers are idempotent; publishing goes through a **transactional outbox**.

### When to go to "full" CQRS

Add a separate read model/store (projections built from events) when:

- reads differ significantly from writes and are a bottleneck (dashboards, search),
- you want to scale reads independently,
- you already have an event stream (Kafka), so projections are "almost free".

**When not to:** small/medium systems — full CQRS is premature complexity (two models to keep
in sync, eventual consistency). YAGNI.

### Event Sourcing — a heavier, separate option

Often confused with CQRS. Event Sourcing means *state = the sum of events* (you persist the
event log, not the current state). It pairs with Kafka but is the **heaviest** option (event
versioning, snapshots, projection rebuilds). Best practice: **don't start with ES** — add it
only when audit/temporality is a hard requirement. This project deliberately does not use it.

## Bounded contexts (modules)

Each module is a bounded context with its own `Domain` / `Application` / `Infrastructure` / `UI`
layers. Modules do **not** share entities; cross-module access goes through ports + adapters,
and integration events (the public contracts) travel over Kafka.

| Module | Responsibility | Publishes | Consumes |
|--------|----------------|-----------|----------|
| Identity | registration, login, roles STUDENT/INSTRUCTOR | — | — |
| Catalog | courses, sections, lessons (instructor CRUD, browsing) | — | — |
| Enrollment | a student enrolls in a course | `UserEnrolled` | — |
| Progress | progress tracking, completion % | `LessonCompleted`, `CourseCompleted` | `UserEnrolled` |
| Notification | emails driven by events (welcome, congrats) | — | `UserEnrolled`, `CourseCompleted` |

Cross-module identifiers are passed as `Uuid` (e.g. `Course.instructorId`, `Enrollment.userId`)
— never as ORM relations across module boundaries.

## Communication

Two levels:

1. **Inside a module** — Symfony Messenger, synchronous buses: `command.bus` (writes) and
   `query.bus` (reads). Command handlers also run inside a DB transaction
   (`doctrine_transaction` middleware) so domain changes and the outbox row commit atomically.
2. **Between modules** — **integration events** as Messenger messages, published to Kafka via a
   custom Kafka transport. Consumers run as separate worker processes with **separate consumer
   groups** (`messenger:consume <transport>`), so each module reacts independently.

## Reliable publishing — transactional outbox

To guarantee an event is published **iff** the domain change committed:

1. A command handler runs inside a DB transaction.
2. The handler saves domain state **and** publishes the event via `EventBusInterface` → the
   `OutboxEventBus` persists an `outbox` row (no separate flush).
3. The transaction commits both atomically (or rolls back both on failure).
4. A separate worker, `app:outbox:relay`, reads unsent rows and dispatches them onto the raw
   `event.bus` (which routes to the Kafka transport), then marks them sent.

The relay is **at-least-once** (it marks a row sent only after a successful dispatch); since
consumers are idempotent (Progress skips if progress already exists; Notification dedupes by
`type + user + course`), re-delivery is safe.

## Data flow example — enrollment

```
POST /courses/{id}/enroll
  → command.bus [doctrine_transaction: BEGIN]
      EnrollStudentHandler: save Enrollment + EventBusInterface.publish(UserEnrolled)
                            → OutboxEventBus persists an outbox row
    [doctrine_transaction: COMMIT]  → enrollment + outbox row, atomically
  app:outbox:relay (separate worker):
      unsent outbox row → event.bus.dispatch(UserEnrolled) → Kafka topic enrollment.events
  consumers (separate groups):
      Progress  → initializes CourseProgress (idempotent)
      Notification → sends the welcome email (idempotent, deduped)
```

## Testing strategy

A test pyramid that does not depend on a live broker:

- **Unit** — pure domain (entities, value objects, completion logic) and handlers with
  in-memory fakes.
- **Integration** — handlers + repositories against a real (test) database, isolated per test
  via `dama/doctrine-test-bundle` (each test runs in a rolled-back transaction). Kafka
  transports resolve to `in-memory://` in the test environment, so event publishing is asserted
  via the in-memory transport / the outbox table.
- **Functional** — controllers via `WebTestCase`.
- **Smoke** — a single real-Kafka round-trip test in the `kafka` group, **excluded from the
  default run** (`vendor/bin/phpunit` skips it; run it with `--group kafka`).

## TL;DR

- **Hexagonal vs CQRS is a false dichotomy** — they are different axes; combine them.
- With Kafka, **hexagonal is practically required** (isolation from `rdkafka`).
- **Best default stack:** hexagonal + light CQRS + event-driven + outbox — what this project uses.
- **Full CQRS (event-driven projections)** and **Event Sourcing** are added *deliberately*, when
  scale/requirements demand — not up front.

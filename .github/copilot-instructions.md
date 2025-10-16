You are a renown senior PHP developer with extensive background in Magento 2 development.

## Overview
- Stack: Magento 2.4.7-p4, PHP 8.2, MySQL Message Queue.
- This is an extension for Magento 2 which implements an integration of the Amazon Pay payment method. 

## Coding Conventions (enforced for new/changed code)
- PHP 8.2 + Magento 2.4.7 best practices.
- Prefer meaningful symbol names over comments.
- Symbol name should be as short as possible while still giving enough context.
- Prefer "exit early" pattern to reduce nesting.
- Imports: no fully‑qualified names in code; add `use` statements.
- Module structure and layering:
  * Keep external contracts in `Api/` and `Api/Data/`; persistence models in `Model/` and `Model/ResourceModel/`; orchestration and pure domain logic in `Service/`; integration points in `Observer/`, `Plugin/`, `Console/Command/`.
  * Maintain module boundaries: prefer publishing domain events or queue messages over direct cross‑module calls. Avoid hard dependencies between modules unless unavoidable.
  * Configuration constants: store config paths in `Interface/ConfigOptionInterface.php` and message topics in `Api/Data/Queue/TopicInterface.php` within each module. If a module lacks them, add incrementally when touching related code.
  * In most cases modules should contain a config option to enable/disable their functionality completely.
- Transactions: 
  * Open DB transactions only at top‑level entry points (controllers, console commands, service methods handling API calls).
  * Wrap multiple database mutations in a transaction (at the top level).
  * In case of processing multiple queue messages or cron jobs, don't wrap the whole loop into one transaction - use separate transactions for each job to properly persist completed jobs.
  * When making mutating external API calls within a transaction, place them after database operations (but still within the transaction) and limit to one per transaction. For multiple mutating API calls, dispatch queue messages instead and handle each call in a separate message handler - ideally one mutating API call per message.
  * See design examples for reference implementations.
- Types handling:
  * Always declare `strict_types=1` in PHP files.
  * Prefer `static` over `self` unless needed. Use `::class` not strings.
  * Fluent methods returning instance: use `static` return type (with `$this` in PHPDoc if present).
  * Avoid casting mixed type; use runtime type checks and throw exceptions for unexpected types.
- Error handling:
  * Don't use Magento-specific exceptions (inherited from the `LocalizedException`) for unexpected situations - Magento doesn't log them.
  * It is ok to not silence exceptions at the top level - we prefer to fail fast to spot issues early.
  * Unexpected situations should not be silently ignored - throw exceptions.
  * Use `ValidationException` class for user input validation errors.
  * Use the following templates for exceptions for incorrect types:
    - In models/services mapping data:
      ```php
      throw new UnexpectedValueException(
        'Incorrect type for Product: expected ' . Product::class . ', got ' . get_debug_type($optionSelection)
      );
      ```
    - In data models with constants:
      ```php
      throw new \UnexpectedValueException(
        'Incorrect type for ' . self::PRODUCT . ': expected ' . HistoryOrderItemProductInterface::class . ', got ' . get_debug_type($product)
      );
      ```
- Logging:
  * Use appropriate log levels for different situations.
  * Avoid logging sensitive information (PII, payment details, etc.).
  * Don't log success paths, log unusual paths only.
  * Magento logs exceptions automatically (except Queue Message Handlers and exception inherited from the `LocalizedException`), don't log errors manually except at the top level of queue message handlers.
- Message Queue:
  * Use the following structure:
    - `Api/Data/Queue/TopicInterface.php` - contains topic name constants
    - `Api/Data/Queue/<Topic>/MessageInterface.php` - message interface (for complex payloads)
    - `Model/Queue/<Topic>/Message.php` - message implementation
    - `Model/Queue/<Topic>/Handler.php` - message handler implementation
  * Queue definitions:
    - `etc/communication.xml` should contain topic nodes with message types and optional handlers nodes inside
    - `etc/queue_topology.xml` should contain exchanges nodes (mandatory attributes: "name") with nested binding nodes to define topics to queues routing (mandatory attributes: "id", "topic", "destination")
    - `etc/queue_publisher.xml` should contain publisher nodes for each topic (mandatory attributes: "topic") with nested connection nodes to define target exchanges (mandatory attributes: "exchange")
    - `etc/queue_consumer.xml` should contain consumer nodes for each queue with optional handlers specified (mandatory attributes: "name", "queue").  
    - Handler should be specified either in `communication.xml` or in `queue_consumer.xml`, but not in both files.
  * Topic naming: `amazon.<module>.<action>` (examples: `amazon.export.order`, `amazon.export.order.update`).
  * Queue Message Handlers should catch all exceptions, log them, and rethrow as `RuntimeException` because Magento silently ignores some types of exception.
  * Queue Message Handlers should clear stateful objects (e.g. Magento repositories) caches in the `finally` block. Services injected into handlers should implement the `ResetAfterRequestInterface` if they use stateful objects.
  * Queue Messages should be dispatched within transactions (e.g. on `_save_after` events) to ensure they are not executed before entities are persisted and that they are removed in case of errors.
- PHPDoc:
  * Use fully‑qualified Fully-Qualified Class Name in PHPDoc; 
  * use PHPStan tags for precise types; 
  * Prefer symbol names over comments; avoid redundant docblocks.
  * Usage of `@var` is discouraged in favor of runtime type checks.
  * Collection classes should contain PHPDoc annotations for `getFirstItem`, `getLastItem`, `getItemById`, `getItemByColumnValue` and `getItems` methods to specify the actual return type.
  * Interfaces in `Api/Data/` should contain PHPDoc for all methods.
- Keep `db_schema_whitelist.json` up to date when touching the database schema.
- Use Implementation Examples.

## Magento Specifics
- The `create` of `SearchCriteriaBuilder` resets the builder state (no need to double check this; take into account that builder methods are chainable).
- Magento auto-generates factories if they are imported via `use` statement (it works both for classes and interfaces). Factory classes may not exist if compilation is not run - ignore corresponding warnings.
- The `getItems` method of Magento collections and SearchResult objects, returned by repository `getList` methods, typically returns arrays indexed by entity IDs rather than sequential numeric keys.

## Design Examples
- Task: we need to save a message into DB and then send it to two external services. 
  Implementation steps:
  * open transaction, 
  * save message, 
  * dispatch separate bus messages to send data to each external service, 
  * commit the transaction.
- Task: we have a list of jobs to send email notifications, jobs are processed by cron. 
  Implementation steps: 
  * fetch the list of pending jobs, prepare all required data,
  * loop through the jobs and wrap processing of each job in a separate transaction,
  * within each transaction:
    - perform all required preparations, 
    - mark the job as completed before sending the email (to avoid duplicate emails in case of failures), 
    - send the email (strictly before committing the transaction),

## Tips
- Generated code: run DI compile before static analysis/IDE indexing.

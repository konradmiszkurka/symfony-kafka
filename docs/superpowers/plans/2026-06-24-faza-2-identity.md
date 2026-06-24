# Faza 2: Identity (rejestracja, logowanie, role) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zbudować moduł **Identity**: rejestracja użytkownika, logowanie (form login), wylogowanie i dwie role STUDENT/INSTRUCTOR — wg hexagonal + lekkie DDD, na fundamencie z Fazy 1.

**Architecture:** Bounded context `Identity` z warstwami Domain/Application/Infrastructure/UI. Encja `User` (Doctrine, implementuje interfejsy Symfony Security — zgodnie ze specem §4). Logika rejestracji w handlerze Application zależnym od portów (`UserRepository`, `PasswordHasher`), które implementuje Infrastructure (Doctrine, Symfony PasswordHasher). UI to kontrolery + formularze Twig. Uwierzytelnianie przez Symfony Security z entity providerem.

**Tech Stack:** Symfony 7.4 Security + Form + Validator (zainstalowany), symfony/uid (Uuid id), Doctrine ORM + migrations, Twig, PHPUnit, dama/doctrine-test-bundle (izolacja testów DB).

## Global Constraints

- PHP **8.4**, Symfony **7.x** — floory, nie obniżać.
- Wszystkie komendy w kontenerze: `docker compose run --rm php <cmd>` (host nie ma PHP/Composera). Usługi DB: najpierw `docker compose up -d mysql` (czeka na `service_healthy`).
- Baza: **MySQL 8.4**. Testy DB izolowane transakcyjnie (dama/doctrine-test-bundle), pristine output (zero deprecations/warningów).
- Namespace PSR-4: `App\Identity\{Domain,Application,Infrastructure,UI}`. **Domain i Application nie zależą od frameworka** — wyjątek dozwolony specem: encja `User` implementuje interfejsy Symfony Security (`UserInterface`, `PasswordAuthenticatedUserInterface`). Cała reszta zależności od Symfony/Doctrine wyłącznie w Infrastructure/UI.
- Komunikacja: command/query przez `command.bus` (Messenger, sync) z Fazy 1; handlery oznaczane `#[AsMessageHandler]`.
- Role: enum `Role` → wartości `ROLE_STUDENT`, `ROLE_INSTRUCTOR`. Każdy user ma dodatkowo `ROLE_USER`.
- **Commity bez śladu AI** (żadnego `Co-Authored-By` ani „Generated with Claude Code"). Git user: `konrad`.
- Każdy task kończy się commitem.

---

## Struktura plików (tworzona/zmieniana w tej fazie)

```
src/Identity/
  Domain/
    User.php                       # encja Doctrine + UserInterface/PasswordAuthenticatedUserInterface
    Role.php                       # enum: Student/Instructor
    UserRepository.php             # port (interfejs)
    Exception/
      EmailAlreadyInUseException.php
  Application/
    PasswordHasher.php             # port (interfejs)
    RegisterUser/
      RegisterUserCommand.php
      RegisterUserHandler.php
  Infrastructure/
    Doctrine/
      DoctrineUserRepository.php   # implementacja UserRepository
    Security/
      SymfonyPasswordHasher.php    # implementacja PasswordHasher (PasswordHasherFactory)
  UI/
    Controller/
      RegistrationController.php
      SecurityController.php
      DashboardController.php       # strona chroniona (demo auth+role)
    Form/
      RegistrationForm.php          # FormType
      RegistrationFormData.php      # DTO z walidacją
migrations/
  Version*.php                      # generowana migracja (tabela users)
templates/
  registration/register.html.twig
  security/login.html.twig
  dashboard/index.html.twig
config/packages/doctrine.yaml       # mapowanie App\Identity\Domain
config/packages/security.yaml       # entity provider, firewall form_login + logout, hasher
config/routes.yaml                  # routing dla src/Identity/UI/Controller
config/services.yaml                # aliasy portów -> implementacje
tests/Unit/Identity/...             # testy domeny + handlera (fakes)
tests/Integration/Identity/...      # repo + handler na realnej DB
tests/Functional/Identity/...       # rejestracja, login, logout, dostęp
```

---

### Task 1: Domena Identity — encja User, enum Role, mapowanie ORM, migracja

**Files:**
- Modify: `config/packages/doctrine.yaml` (mapowanie `App\Identity\Domain`)
- Create: `src/Identity/Domain/Role.php`
- Create: `src/Identity/Domain/User.php`
- Create: `tests/Unit/Identity/Domain/UserTest.php`
- Create: `migrations/Version*.php` (generowana)

**Interfaces:**
- Produces:
  - `enum App\Identity\Domain\Role: string { case Student = 'ROLE_STUDENT'; case Instructor = 'ROLE_INSTRUCTOR'; }`
  - `App\Identity\Domain\User` z: `static register(string $email, string $hashedPassword, Role $role): self`, `getId(): Symfony\Component\Uid\Uuid`, `getEmail(): string`, `getRoles(): array` (zawiera `ROLE_USER`), `getPassword(): string`, `getUserIdentifier(): string` (= email), `eraseCredentials(): void`.

- [ ] **Step 1: Zainstaluj symfony/uid**

Run: `docker compose run --rm php composer require symfony/uid --no-interaction`
Expected: sukces; `symfony/uid` w `composer.json`. (DoctrineBundle automatycznie rejestruje typ `uuid`, gdy symfony/uid jest obecny.)

- [ ] **Step 2: Dodaj mapowanie modułu w `config/packages/doctrine.yaml`**

W sekcji `doctrine.orm` zamień blok `mappings:` (obecnie mapuje `src/Entity`) na mapowanie modułu Identity i wyłącz auto_mapping:

```yaml
        auto_mapping: false
        mappings:
            Identity:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src/Identity/Domain'
                prefix: 'App\Identity\Domain'
                alias: Identity
```

(Kolejne fazy dołożą własne wpisy mapping dla swoich modułów.)

- [ ] **Step 3: Utwórz enum `src/Identity/Domain/Role.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum Role: string
{
    case Student = 'ROLE_STUDENT';
    case Instructor = 'ROLE_INSTRUCTOR';
}
```

- [ ] **Step 4: Napisz failujący test `tests/Unit/Identity/Domain/UserTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class UserTest extends TestCase
{
    public function testRegisterCreatesUserWithUuidAndRole(): void
    {
        $user = User::register('jan@example.com', 'hashed-secret', Role::Student);

        self::assertInstanceOf(Uuid::class, $user->getId());
        self::assertSame('jan@example.com', $user->getEmail());
        self::assertSame('jan@example.com', $user->getUserIdentifier());
        self::assertSame('hashed-secret', $user->getPassword());
    }

    public function testRolesAlwaysIncludeRoleUser(): void
    {
        $student = User::register('s@example.com', 'h', Role::Student);
        $instructor = User::register('i@example.com', 'h', Role::Instructor);

        self::assertEqualsCanonicalizing(['ROLE_STUDENT', 'ROLE_USER'], $student->getRoles());
        self::assertEqualsCanonicalizing(['ROLE_INSTRUCTOR', 'ROLE_USER'], $instructor->getRoles());
    }
}
```

- [ ] **Step 5: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Identity/Domain/UserTest.php`
Expected: FAIL — klasa `App\Identity\Domain\User` nie istnieje.

- [ ] **Step 6: Zaimplementuj `src/Identity/Domain/User.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles;

    private function __construct(Uuid $id, string $email, string $hashedPassword, Role $role)
    {
        $this->id = $id;
        $this->email = $email;
        $this->password = $hashedPassword;
        $this->roles = [$role->value];
    }

    public static function register(string $email, string $hashedPassword, Role $role): self
    {
        return new self(Uuid::v4(), $email, $hashedPassword, $role);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    public function eraseCredentials(): void
    {
        // Brak przechowywanych danych wrażliwych w postaci jawnej.
    }
}
```

- [ ] **Step 7: Uruchom test — ma PRZEJŚĆ (pristine)**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Identity/Domain/UserTest.php`
Expected: PASS (2 testy). Brak deprecations/warningów. Jeśli pojawi się deprecation dot. `eraseCredentials`, zastosuj aktualne zalecenie Symfony 7.4 (metoda pozostaje pusta — deprecation nie powinien wystąpić dla pustej implementacji).

- [ ] **Step 8: Sprawdź poprawność mapowania ORM**

Run: `docker compose up -d mysql && docker compose run --rm php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.` (Mapping `Identity` rozpoznane.)

- [ ] **Step 9: Wygeneruj i zastosuj migrację (tabela users)**

Run: `docker compose run --rm php bin/console doctrine:migrations:diff --no-interaction`
Expected: utworzony plik `migrations/Version*.php` zawierający `CREATE TABLE users` z kolumnami `id` (binary/uuid), `email`, `password`, `roles` (json), unikat na `email`.

Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction`
Expected: migracja zastosowana na bazie `app` (`migrated` / `Successfully`).

Run: `docker compose run --rm php bin/console doctrine:migrations:migrate --no-interaction --env=test`
Expected: ta sama migracja zastosowana na `app_test` (schema testowa gotowa pod kolejne taski).

- [ ] **Step 10: Commit**

```bash
git add src/Identity/Domain/ tests/Unit/Identity/ config/packages/doctrine.yaml migrations/ composer.json composer.lock symfony.lock
git commit -m "feat(identity): encja User + enum Role + mapowanie ORM i migracja (users)"
```

---

### Task 2: Application — porty + RegisterUser (command/handler), wyjątek domenowy

**Files:**
- Create: `src/Identity/Domain/UserRepository.php`
- Create: `src/Identity/Domain/Exception/EmailAlreadyInUseException.php`
- Create: `src/Identity/Application/PasswordHasher.php`
- Create: `src/Identity/Application/RegisterUser/RegisterUserCommand.php`
- Create: `src/Identity/Application/RegisterUser/RegisterUserHandler.php`
- Create: `tests/Unit/Identity/Application/RegisterUserHandlerTest.php`

**Interfaces:**
- Consumes: `App\Identity\Domain\User`, `App\Identity\Domain\Role` (Task 1).
- Produces:
  - `interface App\Identity\Domain\UserRepository { public function save(User $user): void; public function ofEmail(string $email): ?User; public function existsByEmail(string $email): bool; }`
  - `interface App\Identity\Application\PasswordHasher { public function hash(string $plainPassword): string; }`
  - `final readonly class RegisterUserCommand { public function __construct(public string $email, public string $plainPassword, public Role $role) {} }`
  - `RegisterUserHandler` (`#[AsMessageHandler]`, `__invoke(RegisterUserCommand): void`), rzuca `EmailAlreadyInUseException` gdy email zajęty.

- [ ] **Step 1: Utwórz port `src/Identity/Domain/UserRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface UserRepository
{
    public function save(User $user): void;

    public function ofEmail(string $email): ?User;

    public function existsByEmail(string $email): bool;
}
```

- [ ] **Step 2: Utwórz wyjątek `src/Identity/Domain/Exception/EmailAlreadyInUseException.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

final class EmailAlreadyInUseException extends \DomainException
{
    public static function forEmail(string $email): self
    {
        return new self(sprintf('Email "%s" jest już zajęty.', $email));
    }
}
```

- [ ] **Step 3: Utwórz port `src/Identity/Application/PasswordHasher.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Application;

interface PasswordHasher
{
    public function hash(string $plainPassword): string;
}
```

- [ ] **Step 4: Utwórz `src/Identity/Application/RegisterUser/RegisterUserCommand.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Application\RegisterUser;

use App\Identity\Domain\Role;

final readonly class RegisterUserCommand
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public Role $role,
    ) {
    }
}
```

- [ ] **Step 5: Napisz failujący test `tests/Unit/Identity/Application/RegisterUserHandlerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\PasswordHasher;
use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Application\RegisterUser\RegisterUserHandler;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use PHPUnit\Framework\TestCase;

final class RegisterUserHandlerTest extends TestCase
{
    public function testRegistersUserWithHashedPassword(): void
    {
        $repo = new class implements UserRepository {
            public array $saved = [];
            public function save(User $user): void { $this->saved[] = $user; }
            public function ofEmail(string $email): ?User { return null; }
            public function existsByEmail(string $email): bool { return false; }
        };
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string { return 'hashed:'.$plainPassword; }
        };
        $handler = new RegisterUserHandler($repo, $hasher);

        $handler(new RegisterUserCommand('jan@example.com', 'secret123', Role::Student));

        self::assertCount(1, $repo->saved);
        $user = $repo->saved[0];
        self::assertSame('jan@example.com', $user->getEmail());
        self::assertSame('hashed:secret123', $user->getPassword());
        self::assertContains('ROLE_STUDENT', $user->getRoles());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $repo = new class implements UserRepository {
            public function save(User $user): void {}
            public function ofEmail(string $email): ?User { return null; }
            public function existsByEmail(string $email): bool { return true; }
        };
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string { return 'x'; }
        };
        $handler = new RegisterUserHandler($repo, $hasher);

        $this->expectException(EmailAlreadyInUseException::class);
        $handler(new RegisterUserCommand('taken@example.com', 'secret123', Role::Instructor));
    }
}
```

- [ ] **Step 6: Uruchom test — ma FAILOWAĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Identity/Application/RegisterUserHandlerTest.php`
Expected: FAIL — `RegisterUserHandler` nie istnieje.

- [ ] **Step 7: Zaimplementuj `src/Identity/Application/RegisterUser/RegisterUserHandler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Application\RegisterUser;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): void
    {
        if ($this->users->existsByEmail($command->email)) {
            throw EmailAlreadyInUseException::forEmail($command->email);
        }

        $user = User::register(
            $command->email,
            $this->passwordHasher->hash($command->plainPassword),
            $command->role,
        );

        $this->users->save($user);
    }
}
```

- [ ] **Step 8: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Unit/Identity/Application/RegisterUserHandlerTest.php`
Expected: PASS (2 testy), pristine.

- [ ] **Step 9: Commit**

```bash
git add src/Identity/Domain/UserRepository.php src/Identity/Domain/Exception/ src/Identity/Application/ tests/Unit/Identity/Application/
git commit -m "feat(identity): porty UserRepository/PasswordHasher + RegisterUser handler"
```

---

### Task 3: Infrastructure — adaptery Doctrine i Symfony + izolacja testów DB + test integracyjny

**Files:**
- Create: `src/Identity/Infrastructure/Doctrine/DoctrineUserRepository.php`
- Create: `src/Identity/Infrastructure/Security/SymfonyPasswordHasher.php`
- Modify: `config/services.yaml` (aliasy portów → implementacje)
- Modify: `phpunit.dist.xml` (rozszerzenie dama/doctrine-test-bundle)
- Create: `tests/Integration/Identity/RegisterUserIntegrationTest.php`

**Interfaces:**
- Consumes: `UserRepository`, `PasswordHasher` (Task 2), `User` (Task 1), `command.bus` (Faza 1).
- Produces: `DoctrineUserRepository implements UserRepository`, `SymfonyPasswordHasher implements PasswordHasher`; aliasy DI tak, że wstrzykiwanie portów rozwiązuje się na te implementacje.

- [ ] **Step 1: Zainstaluj dama/doctrine-test-bundle (izolacja testów DB)**

Run: `docker compose run --rm php composer require --dev dama/doctrine-test-bundle --no-interaction`
Expected: sukces; bundle dopisany do `config/bundles.php` dla `test` (przez recipe). Jeśli recipe nie dopisał, dodaj ręcznie wpis `DAMA\DoctrineTestBundle\DAMADoctrineTestBundle::class => ['test' => true]`.

- [ ] **Step 2: Włącz rozszerzenie PHPUnit w `phpunit.dist.xml`**

W sekcji `<extensions>` (utwórz ją, jeśli brak, wewnątrz `<phpunit>`):

```xml
    <extensions>
        <bootstrap class="DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension"/>
    </extensions>
```

(Dzięki temu każdy test DB działa w transakcji wycofywanej po teście — izolacja i czystość.)

- [ ] **Step 3: Zaimplementuj `src/Identity/Infrastructure/Doctrine/DoctrineUserRepository.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineUserRepository implements UserRepository
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function save(User $user): void
    {
        $this->em->persist($user);
        $this->em->flush();
    }

    public function ofEmail(string $email): ?User
    {
        return $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    public function existsByEmail(string $email): bool
    {
        return null !== $this->ofEmail($email);
    }
}
```

- [ ] **Step 4: Zaimplementuj `src/Identity/Infrastructure/Security/SymfonyPasswordHasher.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\User;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

final readonly class SymfonyPasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordHasherFactoryInterface $factory)
    {
    }

    public function hash(string $plainPassword): string
    {
        return $this->factory->getPasswordHasher(User::class)->hash($plainPassword);
    }
}
```

- [ ] **Step 5: Zwiąż porty z implementacjami w `config/services.yaml`**

W sekcji `services:` dodaj aliasy (autowiring sam nie aliasuje interfejsu do jedynej implementacji):

```yaml
    App\Identity\Domain\UserRepository:
        alias: App\Identity\Infrastructure\Doctrine\DoctrineUserRepository

    App\Identity\Application\PasswordHasher:
        alias: App\Identity\Infrastructure\Security\SymfonyPasswordHasher
```

- [ ] **Step 6: Napisz failujący test `tests/Integration/Identity/RegisterUserIntegrationTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\Domain\Role;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RegisterUserIntegrationTest extends KernelTestCase
{
    public function testRegisterPersistsUser(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $users = self::getContainer()->get(UserRepository::class);

        $bus->dispatch(new RegisterUserCommand('nowy@example.com', 'secret123', Role::Student));

        $user = $users->ofEmail('nowy@example.com');
        self::assertNotNull($user);
        self::assertSame('nowy@example.com', $user->getEmail());
        self::assertContains('ROLE_STUDENT', $user->getRoles());
        self::assertNotSame('secret123', $user->getPassword(), 'hasło musi być zahaszowane');
    }

    public function testDuplicateEmailThrows(): void
    {
        self::bootKernel();
        $bus = self::getContainer()->get(CommandBusInterface::class);

        $bus->dispatch(new RegisterUserCommand('dup@example.com', 'secret123', Role::Student));

        $this->expectException(EmailAlreadyInUseException::class);
        $bus->dispatch(new RegisterUserCommand('dup@example.com', 'inne123', Role::Instructor));
    }
}
```

- [ ] **Step 7: Uruchom test — ma FAILOWAĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Integration/Identity/RegisterUserIntegrationTest.php`
Expected: FAIL — usługi `UserRepository`/`PasswordHasher` jeszcze niezwiązane lub klasy nieobecne (przed dodaniem aliasów/adapterów). Po dodaniu (kroki 3-5) ponowne uruchomienie ma przejść.

> Uwaga: `CommandBusInterface` i `UserRepository` muszą być pobieralne z kontenera testowego. Są publiczne w środowisku testowym dzięki `test: true` w `framework.yaml` (skeleton ustawia to domyślnie). Jeśli `get()` rzuci „service not found / not public", pobierz je przez `static::getContainer()` (test container udostępnia także prywatne) — co już jest użyte.

- [ ] **Step 8: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Integration/Identity/RegisterUserIntegrationTest.php`
Expected: PASS (2 testy), pristine. Dane wycofane przez dama (brak śmieci w `app_test`).

- [ ] **Step 9: Pełny zestaw testów (regresja)**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: wszystkie dotychczasowe testy zielone (health + unity + integracja), pristine.

- [ ] **Step 10: Commit**

```bash
git add src/Identity/Infrastructure/ config/services.yaml phpunit.dist.xml tests/Integration/ config/bundles.php composer.json composer.lock symfony.lock
git commit -m "feat(identity): adaptery Doctrine/Symfony + izolacja testów DB + test integracyjny rejestracji"
```

---

### Task 4: UI rejestracji — formularz, kontroler, szablon (TDD funkcjonalny)

**Files:**
- Modify: `config/routes.yaml` (routing dla `src/Identity/UI/Controller`)
- Create: `src/Identity/UI/Form/RegistrationFormData.php`
- Create: `src/Identity/UI/Form/RegistrationForm.php`
- Create: `src/Identity/UI/Controller/RegistrationController.php`
- Create: `templates/registration/register.html.twig`
- Create: `tests/Functional/Identity/RegistrationControllerTest.php`

**Interfaces:**
- Consumes: `RegisterUserCommand`, `Role` (Task 2), `CommandBusInterface` (Faza 1), `EmailAlreadyInUseException` (Task 2).
- Produces: trasa `register` (`GET/POST /register`).

- [ ] **Step 1: Zainstaluj komponent formularzy + CSRF**

Run: `docker compose run --rm php composer require symfony/form symfony/security-csrf --no-interaction`
Expected: sukces; formularze i CSRF dostępne (recipe doda `config/packages/csrf.yaml`/form config).

- [ ] **Step 2: Dodaj routing modułu w `config/routes.yaml`**

Dopisz na końcu pliku:

```yaml
identity_controllers:
    resource:
        path: ../src/Identity/UI/Controller/
        namespace: App\Identity\UI\Controller
    type: attribute
```

- [ ] **Step 3: Utwórz DTO `src/Identity/UI/Form/RegistrationFormData.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Role;
use Symfony\Component\Validator\Constraints as Assert;

final class RegistrationFormData
{
    #[Assert\NotBlank]
    #[Assert\Email]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 8, minMessage: 'Hasło musi mieć min. {{ limit }} znaków.')]
    public ?string $plainPassword = null;

    #[Assert\NotNull]
    public ?Role $role = null;
}
```

- [ ] **Step 4: Utwórz `src/Identity/UI/Form/RegistrationForm.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\UI\Form;

use App\Identity\Domain\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RegistrationForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class)
            ->add('plainPassword', PasswordType::class)
            ->add('role', EnumType::class, [
                'class' => Role::class,
                'choice_label' => static fn (Role $role): string => $role === Role::Student ? 'Student' : 'Instruktor',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RegistrationFormData::class]);
    }
}
```

- [ ] **Step 5: Napisz failujący test `tests/Functional/Identity/RegistrationControllerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    public function testUserCanRegister(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register');

        $client->submitForm('Zarejestruj', [
            'registration_form[email]' => 'rejestracja@example.com',
            'registration_form[plainPassword]' => 'secret123',
            'registration_form[role]' => 'ROLE_STUDENT',
        ]);

        self::assertResponseRedirects('/login');

        $users = self::getContainer()->get(UserRepository::class);
        self::assertNotNull($users->ofEmail('rejestracja@example.com'));
    }

    public function testDuplicateEmailShowsError(): void
    {
        $client = static::createClient();

        $register = static function () use ($client): void {
            $client->request('GET', '/register');
            $client->submitForm('Zarejestruj', [
                'registration_form[email]' => 'duplikat@example.com',
                'registration_form[plainPassword]' => 'secret123',
                'registration_form[role]' => 'ROLE_STUDENT',
            ]);
        };

        $register();
        $register();

        self::assertSelectorTextContains('.form-error, .alert', 'zajęty');
    }
}
```

- [ ] **Step 6: Uruchom test — ma FAILOWAĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Identity/RegistrationControllerTest.php`
Expected: FAIL — trasa `/register` nie istnieje (404).

- [ ] **Step 7: Zaimplementuj `src/Identity/UI/Controller/RegistrationController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Exception\EmailAlreadyInUseException;
use App\Identity\UI\Form\RegistrationForm;
use App\Identity\UI\Form\RegistrationFormData;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, CommandBusInterface $commandBus): Response
    {
        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commandBus->dispatch(new RegisterUserCommand($data->email, $data->plainPassword, $data->role));

                return $this->redirectToRoute('login');
            } catch (EmailAlreadyInUseException $e) {
                $form->get('email')->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
            }
        }

        return $this->render('registration/register.html.twig', ['form' => $form]);
    }
}
```

- [ ] **Step 8: Utwórz szablon `templates/registration/register.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Rejestracja{% endblock %}

{% block body %}
    <h1>Rejestracja</h1>
    {{ form_start(form) }}
        {{ form_row(form.email) }}
        {{ form_row(form.plainPassword) }}
        {{ form_row(form.role) }}
        <button type="submit">Zarejestruj</button>
    {{ form_end(form) }}
{% endblock %}
```

- [ ] **Step 9: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/Identity/RegistrationControllerTest.php`
Expected: PASS (3 testy), pristine.

> Jeśli `submitForm` nie znajdzie przycisku po etykiecie „Zarejestruj", sprawdź, że tekst przycisku w szablonie dokładnie się zgadza. Nazwa pola formularza to `registration_form[...]` (od nazwy klasy `RegistrationForm`).

- [ ] **Step 10: Commit**

```bash
git add src/Identity/UI/ templates/registration/ config/routes.yaml tests/Functional/Identity/RegistrationControllerTest.php composer.json composer.lock symfony.lock config/packages/
git commit -m "feat(identity): UI rejestracji (formularz + kontroler + szablon)"
```

---

### Task 5: Bezpieczeństwo — entity provider, firewall (login/logout), strona chroniona (TDD funkcjonalny)

**Files:**
- Modify: `config/packages/security.yaml`
- Create: `src/Identity/UI/Controller/SecurityController.php`
- Create: `src/Identity/UI/Controller/DashboardController.php`
- Create: `templates/security/login.html.twig`
- Create: `templates/dashboard/index.html.twig`
- Create: `tests/Functional/Identity/SecurityControllerTest.php`

**Interfaces:**
- Consumes: `User` (Task 1, entity provider po `email`), `RegisterUserCommand`/`CommandBusInterface` (do seedowania w teście).
- Produces: trasy `login` (`GET/POST /login`), `logout` (`/logout`), `dashboard` (`/dashboard`, chroniona).

- [ ] **Step 1: Skonfiguruj `config/packages/security.yaml`**

Zastąp zawartość (poza blokiem `when@test`, który zostaw) tak:

```yaml
security:
    password_hashers:
        App\Identity\Domain\User: 'auto'

    providers:
        app_user_provider:
            entity:
                class: App\Identity\Domain\User
                property: email

    firewalls:
        dev:
            pattern: ^/(_profiler|_wdt|assets|build)/
            security: false
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: login
                check_path: login
                enable_csrf: true
            logout:
                path: logout

    access_control:
        - { path: ^/dashboard, roles: IS_AUTHENTICATED_FULLY }
```

Zostaw istniejący blok `when@test:` (obniżony koszt hashera) bez zmian — zaktualizuj tylko, jeśli odwołuje się do starego interfejsu; klucz `PasswordAuthenticatedUserInterface` pozostaje poprawny i obejmie `User`.

- [ ] **Step 2: Napisz failujący test `tests/Functional/Identity/SecurityControllerTest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\RegisterUser\RegisterUserCommand;
use App\Identity\Domain\Role;
use App\Shared\Application\Bus\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testProtectedPageRedirectsAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/dashboard');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', $client->getResponse()->headers->get('Location'));
    }

    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        self::getContainer()->get(CommandBusInterface::class)
            ->dispatch(new RegisterUserCommand('login@example.com', 'secret123', Role::Student));

        $client->request('GET', '/login');
        $client->submitForm('Zaloguj', [
            '_username' => 'login@example.com',
            '_password' => 'secret123',
        ]);

        self::assertResponseRedirects();
        $client->followRedirect();
        $client->request('GET', '/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'login@example.com');
    }

    public function testLoginWithInvalidCredentialsShowsError(): void
    {
        $client = static::createClient();
        self::getContainer()->get(CommandBusInterface::class)
            ->dispatch(new RegisterUserCommand('zly@example.com', 'secret123', Role::Student));

        $client->request('GET', '/login');
        $client->submitForm('Zaloguj', [
            '_username' => 'zly@example.com',
            '_password' => 'bledne-haslo',
        ]);

        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('.alert, .error');
    }
}
```

- [ ] **Step 3: Uruchom test — ma FAILOWAĆ**

Run: `docker compose up -d mysql && docker compose run --rm php vendor/bin/phpunit tests/Functional/Identity/SecurityControllerTest.php`
Expected: FAIL — trasy `login`/`dashboard` nie istnieją (404 zamiast przekierowań).

- [ ] **Step 4: Zaimplementuj `src/Identity/UI/Controller/SecurityController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Przechwytywane przez firewall (logout).');
    }
}
```

- [ ] **Step 5: Zaimplementuj `src/Identity/UI/Controller/DashboardController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Identity\UI\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'dashboard', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('dashboard/index.html.twig');
    }
}
```

- [ ] **Step 6: Utwórz szablon `templates/security/login.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Logowanie{% endblock %}

{% block body %}
    <h1>Logowanie</h1>
    {% if error %}
        <div class="alert error">{{ error.messageKey|trans(error.messageData, 'security') }}</div>
    {% endif %}
    <form method="post" action="{{ path('login') }}">
        <input type="hidden" name="_csrf_token" value="{{ csrf_token('authenticate') }}">
        <label>E-mail <input type="email" name="_username" value="{{ last_username }}"></label>
        <label>Hasło <input type="password" name="_password"></label>
        <button type="submit">Zaloguj</button>
    </form>
    <p><a href="{{ path('register') }}">Załóż konto</a></p>
{% endblock %}
```

- [ ] **Step 7: Utwórz szablon `templates/dashboard/index.html.twig`**

```twig
{% extends 'base.html.twig' %}

{% block title %}Panel{% endblock %}

{% block body %}
    <h1>Panel</h1>
    <p>Zalogowano jako: {{ app.user.userIdentifier }}</p>
    <p>Role: {{ app.user.roles|join(', ') }}</p>
    <a href="{{ path('logout') }}">Wyloguj</a>
{% endblock %}
```

- [ ] **Step 8: Uruchom test — ma PRZEJŚĆ**

Run: `docker compose run --rm php vendor/bin/phpunit tests/Functional/Identity/SecurityControllerTest.php`
Expected: PASS (3 testy), pristine.

> Token CSRF logowania musi nazywać się `authenticate` (domyślny id dla `form_login` przy `enable_csrf: true`). Jeśli pojawi się „Invalid CSRF token", sprawdź zgodność `csrf_token('authenticate')` w szablonie z konfiguracją firewalla.

- [ ] **Step 9: Pełny zestaw testów (regresja całej fazy)**

Run: `docker compose run --rm php vendor/bin/phpunit`
Expected: wszystkie testy zielone (Faza 1 + Identity: unit, integration, functional), pristine.

- [ ] **Step 10: Commit**

```bash
git add config/packages/security.yaml src/Identity/UI/Controller/SecurityController.php src/Identity/UI/Controller/DashboardController.php templates/security/ templates/dashboard/ tests/Functional/Identity/SecurityControllerTest.php
git commit -m "feat(identity): logowanie/wylogowanie (form login, entity provider) + strona chroniona"
```

---

## Self-Review (sprawdzenie planu względem specu)

**Pokrycie specu:**
- Rejestracja → Task 2 (handler) + Task 4 (UI) ✅
- Logowanie (form login) → Task 5 ✅
- Wylogowanie → Task 5 (firewall logout) ✅
- Role STUDENT/INSTRUCTOR → Task 1 (enum Role, User.getRoles) ✅
- `User` z id/email/hash/roles implementujący `UserInterface` → Task 1 ✅ (zgodnie ze specem §4)
- Warstwy Domain/Application/Infrastructure/UI, porty → Tasks 1-3 ✅
- Wyjątek domenowy → 4xx/feedback: `EmailAlreadyInUseException` mapowany na błąd formularza (Task 4) ✅
- Bezpieczeństwo dostępu (role) → Task 5 (access_control `/dashboard`) ✅
- Testy: unit (Task 1,2), integration (Task 3), functional (Task 4,5) — piramida ze specu §6 ✅

**Granice modułów:** Identity nie sięga do innych modułów. `User.id` to `Uuid` — stabilny identyfikator pod przyszłe integration eventy (Faza 4, `UserEnrolled` niesie `userId`). Brak współdzielenia encji.

**Placeholdery:** brak „TBD/TODO"; każdy krok ma konkretny kod/komendę. Treść migracji jest generowana (`migrations:diff`) — to celowe, nie placeholder; krok podaje oczekiwany kształt SQL do weryfikacji.

**Spójność typów:** `UserRepository` (`save/ofEmail/existsByEmail`) i `PasswordHasher` (`hash`) zdefiniowane w Task 2 i użyte identycznie w Tasks 3-5. `RegisterUserCommand(email, plainPassword, role: Role)` spójne między Task 2 (definicja), Task 3 (integracja) i Task 4/5 (dispatch). `User::register(string,string,Role)` i `getRoles()/getPassword()/getUserIdentifier()` spójne wszędzie.

**Świadome decyzje techniczne:**
- Encja `User` implementuje interfejsy Symfony Security (sprzężenie Domain↔Security) — pragmatyczne, zgodne ze specem §4; reszta Domain/Application pozostaje framework-free.
- `PasswordHasher` przez `PasswordHasherFactoryInterface` (haszowanie bez instancji User — brak problemu „jajko-kura").
- `dama/doctrine-test-bundle` dla izolacji testów DB (transakcje wycofywane) — czyste, szybkie, reprodukowalne testy.
- Mapowanie ORM przełączone na modułowe (`src/Identity/Domain`) — domyka uwagę z finalnego review Fazy 1.

**Uwaga wykonawcza:** wersje pakietów (`symfony/uid`, `symfony/form`, `symfony/security-csrf`, `dama/doctrine-test-bundle`) pinują się do aktualnych stabilnych; przy konflikcie wybrać zgodne z Symfony 7.4 i odnotować. Jeśli któryś asercyjny selektor testu (`.alert`/`.form-error`) nie pasuje do realnego renderu Twig/Bootstrap, dostosować selektor do faktycznego markupu (zachowując sens asercji), nie osłabiając testu.
```

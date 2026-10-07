<?php

declare(strict_types=1);

namespace Rewloy\Tests\Live;

use Rewloy\Client;
use Rewloy\Exception\RewloyException;
use RuntimeException;
use Throwable;

/**
 * What the live tests share: the clients, one run id, the test business's branch and the
 * programs, webhooks and codes the run creates (so they can be cleaned up where the API allows).
 */
final class Fixture
{
    private static ?string $run = null;

    private static ?Client $client = null;

    private static ?string $locationId = null;

    private static ?string $stampProgramId = null;

    private static ?string $giftProgramId = null;

    private static int $counter = 0;

    /** @var array<string, string> program id => name, for deleteProgram's confirmName */
    private static array $programs = [];

    /** @var list<string> */
    private static array $webhooks = [];

    /** @var list<string> */
    private static array $batches = [];

    /** @var list<string> */
    private static array $groups = [];

    /** @var list<string> programs that got earn rules */
    private static array $ruled = [];

    /** @var list<string> branches this run made */
    private static array $locations = [];

    private static ?Client $staff = null;

    private static ?string $staffError = null;

    private static bool $staffResolved = false;
    private static bool $staffOwned = false;

    private function __construct()
    {
    }

    /** A short id for this run, in every email, key and name the tests make. */
    public static function run(): string
    {
        return self::$run ??= bin2hex(random_bytes(4));
    }

    public static function client(): Client
    {
        return self::$client ??= new Client(
            apiKey: Guard::$apiKey,
            baseUrl: Guard::$baseUrl,
            timeout: 30,
            userAgent: 'rewloy-php-live-tests',
        );
    }

    /** An Idempotency-Key (8-64 visible ASCII characters), unique to this run. */
    public static function key(string $label): string
    {
        return sprintf('phplive-%s-%s-%d', self::run(), $label, ++self::$counter);
    }

    /** A customer address that is never mailed: the test business sends nothing. */
    public static function email(string $label): string
    {
        return sprintf('phplive-%s-%s@ornek.com', self::run(), $label);
    }

    public static function locationId(): string
    {
        if (self::$locationId === null) {
            $locations = self::client()->listLocations();
            $first = $locations[0] ?? null;
            if (!is_array($first) || !is_string($first['id'] ?? null)) {
                throw new RuntimeException('the test business has no branch');
            }
            self::$locationId = $first['id'];
        }
        return self::$locationId;
    }

    /** One stamp program (5 stamps), made on first use. */
    public static function stampProgramId(): string
    {
        return self::$stampProgramId ??= self::newProgram([
            'type' => 'stamp',
            'businessName' => 'Live Kahve',
            'programName' => 'Live damga ' . self::run(),
            'maxStamps' => 5,
            'rewardName' => 'Bedava kahve',
        ]);
    }

    /** One gift card program, made on first use. */
    public static function giftProgramId(): string
    {
        return self::$giftProgramId ??= self::newProgram([
            'type' => 'giftcard',
            'businessName' => 'Live Kahve',
            'programName' => 'Live hediye ' . self::run(),
        ]);
    }

    /**
     * Creates a program and remembers it for cleanup.
     *
     * @param array<string, mixed> $body
     */
    public static function newProgram(array $body): string
    {
        $program = self::client()->createProgram(['body' => $body]);
        $id = $program['id'];
        self::$programs[$id] = $program['name'];
        return $id;
    }

    /** A card on the stamp program for a fresh customer. Returns its serial. */
    public static function stampCard(string $label): string
    {
        $card = self::client()->issuePass(['body' => [
            'programId' => self::stampProgramId(),
            'email' => self::email($label),
            'firstName' => 'Ayşe',
            'kvkkConsent' => true,
        ]]);
        return $card['serial'];
    }

    /** A gift card worth 100,00 on the gift program (no customer). Returns its serial. */
    public static function giftCard(int $faceMinor = 10000): string
    {
        $card = self::client()->issuePass(['body' => ['programId' => self::giftProgramId(), 'faceMinor' => $faceMinor]]);
        return $card['serial'];
    }

    public static function trackWebhook(string $id): void
    {
        self::$webhooks[] = $id;
    }

    public static function trackBatch(string $id): void
    {
        self::$batches[] = $id;
    }

    /** A program the run made some other way (a copy): cleaned up with the rest. */
    public static function adopt(string $id, string $name): void
    {
        self::$programs[$id] = $name;
    }

    public static function trackGroup(string $id): void
    {
        self::$groups[] = $id;
    }

    /** A program whose earn rules the run saved: they are deleted at the end so its groups can go. */
    public static function trackRules(string $programId): void
    {
        self::$ruled[] = $programId;
    }

    public static function trackLocation(string $id): void
    {
        self::$locations[] = $id;
    }

    /** A branch the run made: archived at the end (a branch cannot be deleted). */
    public static function newLocation(string $label): string
    {
        $location = self::client()->createLocation(['body' => ['name' => sprintf('Live %s %s', $label, self::run())]]);
        self::$locations[] = $location['id'];
        return $location['id'];
    }

    /**
     * A team session in the test business, or null (the reason is in staffError()). REWLOY_STAFF_SESSION
     * (an rws_ token), or REWLOY_STAFF_EMAIL and REWLOY_STAFF_PASSWORD (a login without a second factor).
     */
    public static function staff(): ?Client
    {
        if (!self::$staffResolved) {
            self::$staffResolved = true;
            self::$staff = self::openStaffSession();
        }
        return self::$staff;
    }

    /** True only when the suite opened the team session itself (email and password); a handed-in session is not the suite's to close. */
    public static function staffOwned(): bool
    {
        return self::$staffOwned;
    }

    public static function staffError(): string
    {
        return self::$staffError ?? 'no team session given';
    }

    /** The team member's password (REWLOY_STAFF_PASSWORD), which a step-up action such as freezing a branch asks for. */
    public static function staffPassword(): ?string
    {
        $password = (string) getenv('REWLOY_STAFF_PASSWORD');
        return $password === '' ? null : $password;
    }

    private static function openStaffSession(): ?Client
    {
        $merchant = self::client()->getBusiness()['id'];
        $token = trim((string) getenv('REWLOY_STAFF_SESSION'));
        if ($token === '') {
            $email = trim((string) getenv('REWLOY_STAFF_EMAIL'));
            $password = (string) getenv('REWLOY_STAFF_PASSWORD');
            if ($email === '' || $password === '') {
                self::$staffError = 'needs a team session: set REWLOY_STAFF_SESSION, or REWLOY_STAFF_EMAIL and REWLOY_STAFF_PASSWORD';
                return null;
            }
            $login = (new Client(baseUrl: Guard::$baseUrl))->login(['body' => ['email' => $email, 'password' => $password]]);
            if ($login['mfaRequired']) {
                self::$staffError = 'the staff login asks for a second factor: give REWLOY_STAFF_SESSION of a proven session instead';
                return null;
            }
            $token = $login['token'];
            self::$staffOwned = true;
        }
        return new Client(staffSession: $token, merchant: $merchant, baseUrl: Guard::$baseUrl, userAgent: 'rewloy-php-live-tests');
    }

    public static function forgetProgram(string $id): void
    {
        unset(self::$programs[$id]);
    }

    /**
     * Best-effort tidy-up of what the run made, whatever happened: webhooks deleted, codes closed,
     * programs deleted (or archived when they still hold cards, which only a reset clears).
     * Never throws.
     */
    public static function cleanup(): void
    {
        if (Guard::$skip !== null || self::$client === null) {
            return;
        }
        $client = self::client();
        foreach (self::$ruled as $id) {
            self::quietly(static fn () => $client->deleteEarnRules(['params' => ['id' => $id]]));
        }
        self::$ruled = [];
        foreach (self::$groups as $id) {
            self::quietly(static fn () => $client->deleteEarnGroup(['params' => ['id' => $id]]));
        }
        self::$groups = [];
        foreach (self::$webhooks as $id) {
            self::quietly(static fn () => $client->deleteWebhook(['params' => ['id' => $id]]));
        }
        self::$webhooks = [];
        foreach (self::$batches as $id) {
            self::quietly(static fn () => $client->closeBatch(['params' => ['id' => $id]]));
        }
        self::$batches = [];
        foreach (self::$programs as $id => $name) {
            $deleted = self::quietly(static fn () => $client->deleteProgram(['params' => ['id' => $id], 'query' => ['confirmName' => $name]]));
            if (!$deleted) {
                self::quietly(static fn () => $client->archiveProgram(['params' => ['id' => $id]]));
            }
        }
        self::$programs = [];
        foreach (self::$locations as $id) {
            self::quietly(static fn () => $client->archiveLocation(['params' => ['id' => $id]]));
        }
        self::$locations = [];
    }

    /** @param callable(): mixed $call */
    private static function quietly(callable $call): bool
    {
        try {
            $call();
            return true;
        } catch (RewloyException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }
}

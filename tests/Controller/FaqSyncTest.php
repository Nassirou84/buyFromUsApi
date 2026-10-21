<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

use const JSON_THROW_ON_ERROR;

final class FaqSyncTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropDatabase();
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());

        $this->admin = (new User())
            ->setEmail('admin@test.com')
            ->setFirstName('Ad')
            ->setLastName('Min')
            ->setRoles([User::ROLE_ADMIN])
            ->setPassword('x');
        $em->persist($this->admin);
        $em->flush();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function request(string $method, string $uri, array $body = []): array
    {
        // The api firewall is stateless, so loginUser() only covers one request: use a fresh client for each.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $admin = static::getContainer()->get(EntityManagerInterface::class)->find(User::class, $this->admin->getId());
        $this->client->loginUser($admin, 'api');
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], [] === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function category(string $slug, array $items, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'cat-'.$slug,
            'slug' => $slug,
            'title' => ucfirst($slug),
            'icon' => 'help_outline',
            'accordionBehavior' => 'multi-open',
            'targetPages' => ['all_pages'],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $question, bool $published = true, ?string $id = null): array
    {
        return [
            'id' => $id ?? 'faq-'.$question,
            'question' => $question,
            'elements' => [['id' => 'el-1', 'type' => 'paragraph', 'text' => 'Answer']],
            'published' => $published,
            'highlight' => false,
            'tags' => ['a'],
            'cta' => ['label' => 'Go', 'url' => '/go'],
            'satisfactionRate' => 0.9,
        ];
    }

    public function testSyncCreatesUpdatesAndDeletes(): void
    {
        $saved = $this->request('PUT', '/api/admin/faq', ['categories' => [
            $this->category('shipping', [$this->item('Q1'), $this->item('Q2')]),
            $this->category('returns', [$this->item('Q3')]),
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['shipping', 'returns'], array_column($saved, 'slug'));
        self::assertSame(2, $saved[0]['faqsCount']);
        self::assertMatchesRegularExpression('/^\d+$/', $saved[0]['id']);

        $shippingId = $saved[0]['id'];
        $q1Id = $saved[0]['items'][0]['id'];

        // Edit Q1, drop Q2, add Q4, delete the "returns" category, add a new one.
        $saved = $this->request('PUT', '/api/admin/faq', ['categories' => [
            $this->category('shipping', [$this->item('Q1 edited', true, $q1Id), $this->item('Q4')], $shippingId),
            $this->category('payments', []),
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['shipping', 'payments'], array_column($saved, 'slug'));
        self::assertSame($shippingId, $saved[0]['id']);
        self::assertSame(['Q1 edited', 'Q4'], array_column($saved[0]['items'], 'question'));
        self::assertSame($q1Id, $saved[0]['items'][0]['id']);
        self::assertSame([1, 2], array_column($saved[0]['items'], 'order'));
        self::assertSame(['id' => 'el-1', 'type' => 'paragraph', 'text' => 'Answer'], $saved[0]['items'][0]['elements'][0]);

        $stored = $this->request('GET', '/api/admin/faq');
        self::assertSame(['shipping', 'payments'], array_column($stored, 'slug'));
    }

    public function testNewCategoryCanReuseSlugOfRemovedOne(): void
    {
        $this->request('PUT', '/api/admin/faq', ['categories' => [$this->category('shipping', [])]]);
        $saved = $this->request('PUT', '/api/admin/faq', ['categories' => [$this->category('shipping', [], 'cat-new')]]);

        self::assertResponseIsSuccessful();
        self::assertSame(['shipping'], array_column($saved, 'slug'));
    }

    public function testPublicListHidesDraftsAndEmptyCategories(): void
    {
        $this->request('PUT', '/api/admin/faq', ['categories' => [
            $this->category('shipping', [$this->item('Live'), $this->item('Draft', false)]),
            $this->category('empty', [$this->item('Hidden', false)]),
        ]]);

        $public = $this->request('GET', '/api/faq');

        self::assertResponseIsSuccessful();
        self::assertSame(['shipping'], array_column($public, 'slug'));
        self::assertSame(['Live'], array_column($public[0]['items'], 'question'));
    }

    public function testInvalidPayloadIsRejectedAndNothingIsSaved(): void
    {
        $this->request('PUT', '/api/admin/faq', ['categories' => [$this->category('shipping', [$this->item('Q1')])]]);

        $bad = $this->item('Q');
        $bad['cta'] = ['label' => 'x', 'url' => 'javascript:alert(1)'];
        $bad['elements'] = [['type' => 'script']];
        $response = $this->request('PUT', '/api/admin/faq', ['categories' => [
            $this->category('Bad Slug', [$bad]),
            $this->category('dup', []),
            $this->category('dup', []),
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertGreaterThanOrEqual(4, \count($response['details']));
        self::assertSame(['shipping'], array_column($this->request('GET', '/api/admin/faq'), 'slug'));
    }

    public function testCategoryCanBeCreatedAndEdited(): void
    {
        $created = $this->request('POST', '/api/admin/faq/categories', [
            'title' => 'Shipping',
            'slug' => 'shipping',
            'icon' => 'local_shipping',
            'accordionBehavior' => 'strict',
            'targetPages' => ['checkout'],
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['shipping', 'Shipping', 1, []], [$created['slug'], $created['title'], $created['displayOrder'], $created['items']]);

        $second = $this->request('POST', '/api/admin/faq/categories', ['title' => 'Returns', 'slug' => 'returns']);
        self::assertSame(2, $second['displayOrder']);
        self::assertSame(['all_pages'], $second['targetPages']);

        // Only the sent fields change.
        $edited = $this->request('PATCH', '/api/admin/faq/categories/'.$created['id'], ['title' => 'Delivery', 'icon' => 'truck']);
        self::assertResponseIsSuccessful();
        self::assertSame(['Delivery', 'shipping', 'truck', 'strict', ['checkout']], [
            $edited['title'], $edited['slug'], $edited['icon'], $edited['accordionBehavior'], $edited['targetPages'],
        ]);

        self::assertSame(['Delivery', 'Returns'], array_column($this->request('GET', '/api/admin/faq'), 'title'));
    }

    public function testEditingACategoryKeepsItsQuestions(): void
    {
        $saved = $this->request('PUT', '/api/admin/faq', ['categories' => [$this->category('shipping', [$this->item('Q1')])]]);

        $edited = $this->request('PATCH', '/api/admin/faq/categories/'.$saved[0]['id'], ['title' => 'Renamed']);

        self::assertResponseIsSuccessful();
        self::assertSame(['Q1'], array_column($edited['items'], 'question'));
    }

    public function testCategorySlugMustBeUniqueAndValid(): void
    {
        $first = $this->request('POST', '/api/admin/faq/categories', ['title' => 'A', 'slug' => 'a']);
        $second = $this->request('POST', '/api/admin/faq/categories', ['title' => 'B', 'slug' => 'b']);

        $this->request('POST', '/api/admin/faq/categories', ['title' => 'Again', 'slug' => 'a']);
        self::assertResponseStatusCodeSame(409);

        $this->request('PATCH', '/api/admin/faq/categories/'.$second['id'], ['slug' => 'a']);
        self::assertResponseStatusCodeSame(409);

        // Keeping its own slug is fine.
        $this->request('PATCH', '/api/admin/faq/categories/'.$first['id'], ['slug' => 'a', 'title' => 'A2']);
        self::assertResponseIsSuccessful();

        $this->request('POST', '/api/admin/faq/categories', ['title' => '', 'slug' => 'Bad Slug', 'accordionBehavior' => 'nope']);
        self::assertResponseStatusCodeSame(422);

        $this->request('PATCH', '/api/admin/faq/categories/9999', ['title' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }
}

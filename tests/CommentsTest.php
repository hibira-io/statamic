<?php

namespace Hibira\Statamic\Tests;

use Hibira\Statamic\Comments\CommentStore;

class CommentsTest extends TestCase
{
    use FakesRoles;

    public function test_comments_are_added_listed_and_sent_to_the_others(): void
    {
        $this->entry('post-1');
        $user = $this->superUser();

        $response = $this->actingAs($user)
            ->withHeader('X-Socket-ID', '11.22')
            ->postJson('/cp/hibira/entries/post-1/comments', ['body' => "  Can we shorten the intro?\r\nThanks  "]);

        $response->assertCreated()
            ->assertJsonPath('comment.body', "Can we shorten the intro?\nThanks")
            ->assertJsonPath('comment.author.name', 'Ada Admin')
            ->assertJsonPath('comment.resolved_at', null);

        $sent = $this->realtime->sent('comment');
        $this->assertCount(1, $sent);
        $this->assertSame('presence-hibira-statamic.entry.post-1', $sent[0]['channel']);
        $this->assertSame('added', $sent[0]['data']['action']);
        $this->assertSame('11.22', $sent[0]['except']);
        $this->assertSame(['action' => 'added', 'id' => $response->json('comment.id')], $sent[0]['data']);

        $this->actingAs($user)->getJson('/cp/hibira/entries/post-1/comments')
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.body', "Can we shorten the intro?\nThanks");

        $this->assertFileExists($this->commentsPath.'/post-1.json');
    }

    public function test_empty_and_too_long_comments_are_refused(): void
    {
        $this->entry('post-1');
        $user = $this->superUser();
        config(['hibira-statamic.comments.max_length' => 10]);

        $this->actingAs($user)->postJson('/cp/hibira/entries/post-1/comments', ['body' => ''])->assertStatus(422);
        $this->actingAs($user)->postJson('/cp/hibira/entries/post-1/comments', ['body' => str_repeat('a', 11)])->assertStatus(422);
        $this->actingAs($user)->postJson('/cp/hibira/entries/missing/comments', ['body' => 'Hi'])->assertNotFound();
    }

    public function test_long_comments_are_announced_by_id_only(): void
    {
        $this->entry('post-1');
        $body = str_repeat('留言', 900);

        $id = $this->actingAs($this->superUser())
            ->postJson('/cp/hibira/entries/post-1/comments', ['body' => $body])
            ->assertCreated()
            ->json('comment.id');

        $sent = $this->realtime->sent('comment')[0];
        $this->assertSame(['action' => 'added', 'id' => $id], $sent['data']);
        $this->assertLessThan(200, strlen(json_encode($sent['data'])));
    }

    public function test_invalid_utf8_is_refused_and_keeps_the_existing_comments(): void
    {
        $this->entry('post-1');
        $store = app(CommentStore::class);
        $store->add('post-1', ['id' => 'ada', 'name' => 'Ada'], 'Keep me');

        $this->actingAs($this->superUser())
            ->post('/cp/hibira/entries/post-1/comments', ['body' => "bad \xB1\x31"], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertSame(['Keep me'], array_column($store->all('post-1'), 'body'));
    }

    public function test_a_comment_that_cannot_be_encoded_leaves_the_file_untouched(): void
    {
        $this->entry('post-1');
        $store = app(CommentStore::class);
        $store->add('post-1', ['id' => 'ada', 'name' => 'Ada'], 'Keep me');

        try {
            $store->add('post-1', ['id' => 'ada', 'name' => 'Ada'], "bad \xB1\x31");
            $this->fail('Expected the encoding to fail.');
        } catch (\JsonException) {
        }

        $this->assertSame(['Keep me'], array_column($store->all('post-1'), 'body'));
    }

    public function test_comments_can_be_resolved_and_reopened(): void
    {
        $this->entry('post-1');
        $user = $this->superUser();
        $id = $this->actingAs($user)->postJson('/cp/hibira/entries/post-1/comments', ['body' => 'Fix the typo'])->json('comment.id');

        $this->actingAs($user)->postJson("/cp/hibira/entries/post-1/comments/{$id}/resolve", ['resolved' => true])
            ->assertOk()
            ->assertJsonPath('comment.resolved_by.name', 'Ada Admin');
        $this->assertNotNull(app(CommentStore::class)->find('post-1', $id)['resolved_at']);
        $this->assertSame(0, app(CommentStore::class)->count('post-1'));

        $this->actingAs($user)->postJson("/cp/hibira/entries/post-1/comments/{$id}/resolve", ['resolved' => false])
            ->assertOk()
            ->assertJsonPath('comment.resolved_at', null);

        $this->assertSame(['added', 'updated', 'updated'], array_map(
            fn ($sent) => $sent['data']['action'],
            $this->realtime->sent('comment'),
        ));
    }

    public function test_only_the_author_or_a_super_user_can_delete_a_comment(): void
    {
        $this->entry('post-1');
        $ada = $this->superUser('ada', 'Ada');
        $bob = $this->superUser('bob', 'Bob');

        $store = app(CommentStore::class);
        $comment = $store->add('post-1', ['id' => 'someone-else', 'name' => 'Someone'], 'Hello');

        // Super users may delete anyone's comment.
        $this->actingAs($ada)->deleteJson("/cp/hibira/entries/post-1/comments/{$comment['id']}")->assertOk();
        $this->assertNull($store->find('post-1', $comment['id']));
        $this->assertSame('deleted', $this->realtime->sent('comment')[0]['data']['action']);

        $this->actingAs($bob)->deleteJson('/cp/hibira/entries/post-1/comments/nope')->assertNotFound();
    }

    public function test_non_super_users_can_delete_only_their_own_comments(): void
    {
        $this->setTestRoles(['editor' => ['access cp', 'view blog entries']]);
        $this->entry('post-1');

        $editor = $this->plainUser('ed', 'Ed');
        $editor->assignRole('editor')->save();

        $store = app(CommentStore::class);
        $theirs = $store->add('post-1', ['id' => 'someone-else', 'name' => 'Someone'], 'Hello');

        $mine = $this->actingAs($editor)->postJson('/cp/hibira/entries/post-1/comments', ['body' => 'Mine'])
            ->assertCreated()
            ->json('comment.id');

        $this->actingAs($editor)->deleteJson("/cp/hibira/entries/post-1/comments/{$theirs['id']}")->assertForbidden();
        $this->actingAs($editor)->deleteJson("/cp/hibira/entries/post-1/comments/{$mine}")->assertOk();
    }

    public function test_comments_go_when_the_entry_is_deleted(): void
    {
        $entry = $this->entry('post-1');
        app(CommentStore::class)->add('post-1', ['id' => 'a', 'name' => 'A'], 'Hello');

        $entry->delete();

        $this->assertSame([], app(CommentStore::class)->all('post-1'));
    }

    public function test_the_store_rejects_ids_that_could_escape_the_folder(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CommentStore::class)->all('../secret');
    }
}

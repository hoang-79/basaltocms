<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Live\Live;
use Basalto\Core\Models\Site;
use Basalto\Core\Notifications\Notify;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Konversationen (CR-006 FO-K11, FO-19): private Nachrichten mit mehreren Teilnehmern, modul-unabhängig im Kern. Nur
 * wer teilnimmt (und nicht gegangen ist), liest. Wer eine Person blockiert hat, bekommt von ihr keine Konversation.
 * Gemeldet wird eine einzelne Nachricht — die Moderation sieht nur sie, nie die ganze Konversation.
 */
final class Conversations
{
    public const NOTIFICATION = 'community.conversation';

    public const REPORT_TYPE = 'conversation_message';

    public const MAX_PARTICIPANTS = 20;

    public function __construct(private readonly Relations $relations) {}

    /**
     * Wer von den Empfängern die absendende Person blockiert hat — sie können nicht eingeladen werden.
     *
     * @param  list<Customer>  $to
     * @return list<Customer>
     */
    public function refusing(Customer $from, array $to): array
    {
        return array_values(array_filter($to, fn (Customer $c): bool => $this->relations->blocks($c->id, $from->id)));
    }

    /**
     * @param  list<Customer>  $to
     * @param  array<string, mixed>  $body
     */
    public function start(Site $site, Customer $from, array $to, string $subject, array $body): Conversation
    {
        $conversation = DB::transaction(function () use ($site, $from, $to, $subject, $body): Conversation {
            $conversation = Conversation::query()->withoutGlobalScopes()->create(['site_id' => $site->id, 'subject' => mb_substr(trim($subject), 0, 160), 'customer_id' => $from->id]);
            foreach ([$from, ...$to] as $customer) {
                DB::table('community_conversation_participants')->insertOrIgnore(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'created_at' => now()]);
            }
            $this->addMessage($conversation, $from, $body);

            return $conversation;
        });
        $this->notify($site, $conversation, $from, true);

        return $conversation;
    }

    /** @param  array<string, mixed>  $body */
    public function reply(Site $site, Conversation $conversation, Customer $from, array $body): ConversationMessage
    {
        $message = $this->addMessage($conversation, $from, $body);
        $this->notify($site, $conversation, $from, false);

        return $message;
    }

    /** @param  list<Customer>  $customers */
    public function invite(Site $site, Conversation $conversation, Customer $by, array $customers): int
    {
        $added = 0;
        foreach ($customers as $customer) {
            if ($this->participantCount($conversation) >= self::MAX_PARTICIPANTS) {
                break;
            }
            $existing = DB::table('community_conversation_participants')->where('conversation_id', $conversation->id)->where('customer_id', $customer->id)->first();
            if ($existing === null) {
                DB::table('community_conversation_participants')->insert(['conversation_id' => $conversation->id, 'customer_id' => $customer->id, 'created_at' => now()]);
                $added++;
            } elseif ($existing->left_at !== null) {
                DB::table('community_conversation_participants')->where('id', $existing->id)->update(['left_at' => null]);
                $added++;
            }
        }
        if ($added > 0) {
            Notify::make(self::NOTIFICATION)->site($site)->title(':name hat Sie zur Konversation «:subject» eingeladen', ['name' => app(Profiles::class)->displayName($by), 'subject' => $conversation->subject])
                ->url($this->url($site, $conversation), 'Konversation öffnen')->to(array_values(array_filter($customers, fn (Customer $c): bool => ! $this->relations->blocks($c->id, $by->id))))->send();
        }

        return $added;
    }

    public function leave(Conversation $conversation, Customer $customer): void
    {
        DB::table('community_conversation_participants')->where('conversation_id', $conversation->id)->where('customer_id', $customer->id)->update(['left_at' => now()]);
    }

    public function markRead(Conversation $conversation, Customer $customer): void
    {
        DB::table('community_conversation_participants')->where('conversation_id', $conversation->id)->where('customer_id', $customer->id)->update(['read_at' => now()]);
    }

    public function canRead(Conversation $conversation, ?Customer $customer): bool
    {
        return $customer !== null && $conversation->site_id === $customer->site_id && DB::table('community_conversation_participants')
            ->where('conversation_id', $conversation->id)->where('customer_id', $customer->id)->whereNull('left_at')->exists();
    }

    /** @return Collection<int, Conversation> die Konversationen der Person, neueste zuerst */
    public function of(Customer $customer, int $limit = 50): Collection
    {
        return Conversation::query()->withoutGlobalScopes()->where('site_id', $customer->site_id)
            ->whereIn('id', DB::table('community_conversation_participants')->where('customer_id', $customer->id)->whereNull('left_at')->select('conversation_id'))
            ->orderByDesc('last_message_at')->limit($limit)->get();
    }

    /** @return list<int> Konversationen mit Nachrichten, die die Person noch nicht gelesen hat */
    public function unreadIds(?Customer $customer): array
    {
        if ($customer === null) {
            return [];
        }

        return array_values(array_map('intval', DB::table('community_conversation_participants as p')
            ->join('community_conversations as c', 'c.id', '=', 'p.conversation_id')
            ->where('p.customer_id', $customer->id)->whereNull('p.left_at')->where('c.last_customer_id', '!=', $customer->id)
            ->where(static fn ($q) => $q->whereNull('p.read_at')->orWhereColumn('p.read_at', '<', 'c.last_message_at'))
            ->pluck('c.id')->all()));
    }

    /** @return list<int> wer teilnimmt (ohne die Gegangenen) */
    public function participants(Conversation $conversation): array
    {
        return array_values(array_map('intval', DB::table('community_conversation_participants')->where('conversation_id', $conversation->id)->whereNull('left_at')->orderBy('id')->pluck('customer_id')->all()));
    }

    public function participantCount(Conversation $conversation): int
    {
        return count($this->participants($conversation));
    }

    /** Die Moderation entfernt eine gemeldete Nachricht: der Text ist weg, die Stelle bleibt. */
    public function removeMessage(ConversationMessage $message): void
    {
        $message->forceFill(['body' => null, 'removed_at' => now()])->save();
    }

    public function url(Site $site, Conversation $conversation): ?string
    {
        $base = app(Mounts::class)->baseFor($site);

        return $base === null ? null : $base.'/konversationen/'.$conversation->id;
    }

    /** @param  array<string, mixed>  $body */
    private function addMessage(Conversation $conversation, Customer $from, array $body): ConversationMessage
    {
        $message = ConversationMessage::query()->withoutGlobalScopes()->create(['site_id' => $conversation->site_id, 'conversation_id' => $conversation->id, 'customer_id' => $from->id, 'body' => $body]);
        $conversation->forceFill(['last_message_at' => $message->created_at, 'last_customer_id' => $from->id, 'messages' => $conversation->messages + 1])->save();
        $this->markRead($conversation, $from);
        // CR-008: die offene Konversation und die Zähler «Nachrichten» der anderen Teilnehmer
        $live = app(Live::class);
        $live->signal($conversation->site_id, 'konversation.'.$conversation->id);
        foreach ($this->participants($conversation) as $id) {
            if ($id !== $from->id) {
                $live->signal($conversation->site_id, 'konto.'.$id);
            }
        }

        return $message;
    }

    private function notify(Site $site, Conversation $conversation, Customer $from, bool $isNew): void
    {
        $ids = array_values(array_filter($this->participants($conversation), fn (int $id): bool => $id !== $from->id && ! $this->relations->blocks($id, $from->id)));
        if ($ids === []) {
            return;
        }
        $to = Customer::query()->withoutGlobalScopes()->whereIn('id', $ids)->get();
        $name = app(Profiles::class)->displayName($from);
        $notify = Notify::make(self::NOTIFICATION)->site($site)
            ->title($isNew ? ':name hat Ihnen geschrieben: «:subject»' : ':name hat in «:subject» geantwortet', ['name' => $name, 'subject' => $conversation->subject])
            ->url($this->url($site, $conversation), 'Konversation öffnen')->to($to);
        if (! $isNew) {
            $notify->titleMany(':count neue Nachrichten in «:subject»')->collapse('conversation:'.$conversation->id);
        }
        $notify->send();
    }
}

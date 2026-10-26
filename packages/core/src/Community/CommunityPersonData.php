<?php

declare(strict_types=1);

namespace Basalto\Core\Community;

use Basalto\Core\Content\RichText;
use Basalto\Core\FrontendAccounts\Customer;
use Basalto\Core\Support\Avatars;
use Illuminate\Support\Facades\DB;

/**
 * Community-Profile im Datenschutz des Kerns (N-05, CR-006 FO-23): Auskunft über Anzeigename, Signatur, Gruppen;
 * Löschen entfernt Profil, Bilder, Gruppenzuweisungen, Folgen, Blockieren, Reaktionen, Abos, Trophäen, Verwarnungen und
 * Meldungen. Beiträge der Module und Nachrichten in Konversationen bleiben anonym stehen.
 */
final class CommunityPersonData
{
    /** @return array<string, mixed> */
    public function __invoke(string $email, bool $delete): array
    {
        $ids = Customer::byEmail($email)->pluck('id')->all();
        $profiles = CommunityProfile::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->get();
        $groups = DB::table('visitor_group_customer')->join('visitor_groups', 'visitor_groups.id', '=', 'visitor_group_customer.visitor_group_id')
            ->whereIn('visitor_group_customer.customer_id', $ids)->pluck('visitor_groups.name')->all();
        $messages = ConversationMessage::query()->withoutGlobalScopes()->whereIn('customer_id', $ids);
        // TB 52: Einträge auf Pinnwänden — geschrieben oder auf dem eigenen Profil
        $wall = CommunityWallPost::query()->withoutGlobalScopes()->where(static fn ($q) => $q->whereIn('customer_id', $ids)->orWhereIn('profile_id', $profiles->pluck('id')->all()));
        if (! $delete) {
            $hasMore = (clone $messages)->exists() || (clone $wall)->exists() || DB::table('community_follows')->whereIn('customer_id', $ids)->exists() || DB::table('community_reactions')->whereIn('customer_id', $ids)->exists();

            return $profiles->isEmpty() && $groups === [] && ! $hasMore && ! CommunityAttachment::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->exists() ? [] : ['community' => [
                'profile' => $profiles->map(static fn (CommunityProfile $p): array => ['site' => $p->site_id, 'name' => $p->name, 'signatur' => $p->signature,
                    'beitraege' => $p->posts, 'profilbild' => $p->avatar_path !== null, 'team' => $p->isTeam(), 'zuletzt_aktiv' => $p->last_activity_at?->toIso8601String()])->values()->all(),
                'gruppen' => $groups,
                'anhaenge' => CommunityAttachment::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->orderBy('id')->get()
                    ->map(static fn (CommunityAttachment $a): array => ['site' => $a->site_id, 'name' => $a->name, 'groesse' => $a->size, 'zeit' => $a->created_at?->toIso8601String()])->values()->all(),
                // TB 40/41 (FO-23): Über mich, Felder, Folgen, Blockieren, Nachrichten, Reaktionen, Trophäen, Verwarnungen, Meldungen
                'ueber_mich' => $profiles->map(static fn (CommunityProfile $p): array => ['text' => $p->about, 'felder' => $p->fields ?? [], 'cover' => $p->cover_path !== null])->values()->all(),
                'folgt' => DB::table('community_follows')->whereIn('customer_id', $ids)->count(),
                'blockiert' => DB::table('community_blocks')->whereIn('customer_id', $ids)->count(),
                'nachrichten' => (clone $messages)->orderBy('id')->get()->map(static fn (ConversationMessage $m): array => ['konversation' => $m->conversation_id,
                    'zeit' => $m->created_at?->toIso8601String(), 'text' => RichText::toText($m->body ?? [])])->values()->all(),
                'reaktionen' => DB::table('community_reactions')->whereIn('customer_id', $ids)->get(['subject_type', 'subject_id', 'reaction'])->map(static fn (object $r): array => (array) $r)->all(),
                'trophaeen' => DB::table('community_customer_trophies')->join('community_trophies', 'community_trophies.id', '=', 'community_customer_trophies.trophy_id')
                    ->whereIn('community_customer_trophies.customer_id', $ids)->pluck('community_trophies.title')->all(),
                'verwarnungen' => CommunityWarning::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->get()->map(static fn (CommunityWarning $w): array => ['grund' => $w->reason,
                    'punkte' => $w->points, 'zeit' => $w->created_at?->toIso8601String()])->values()->all(),
                'meldungen' => CommunityReport::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->get()->map(static fn (CommunityReport $r): array => ['art' => $r->subject_type,
                    'grund' => $r->reason, 'notiz' => $r->note, 'zeit' => $r->created_at?->toIso8601String()])->values()->all(),
                // CR-011 TB 60: Zustimmungen zu den Bedingungen der Community (Nachweis; gelöscht mit dem Konto)
                'bedingungen' => CommunityTermsConsent::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->orderBy('id')->get()
                    ->map(static fn (CommunityTermsConsent $c): array => ['site' => $c->site_id, 'fassung' => $c->version, 'zugestimmt' => $c->accepted_at->toIso8601String(),
                        'widerrufen' => $c->revoked_at?->toIso8601String()])->values()->all(),
                'pinnwand' => (clone $wall)->orderBy('id')->get()->map(static fn (CommunityWallPost $w): array => ['profil' => $w->profile_id, 'geschrieben_von_ihnen' => in_array($w->customer_id, $ids, true),
                    'zeit' => $w->created_at?->toIso8601String(), 'text' => RichText::toText($w->body)])->values()->all(),
            ]];
        }
        (clone $wall)->delete();
        $attachments = CommunityAttachment::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->get();
        foreach ($attachments as $attachment) {
            app(Attachments::class)->delete($attachment);
        }
        foreach ($profiles as $profile) {
            Avatars::delete($profile);
            Covers::delete($profile);
            $profile->delete();
        }
        DB::table('visitor_group_customer')->whereIn('customer_id', $ids)->delete();
        // Nachrichten bleiben für die anderen Teilnehmer stehen, ohne Person; alles Persönliche geht
        $anonymized = (clone $messages)->update(['customer_id' => null]);
        foreach (['community_follows' => ['customer_id', 'followed_id'], 'community_blocks' => ['customer_id', 'blocked_id']] as $table => [$a, $b]) {
            DB::table($table)->whereIn($a, $ids)->orWhereIn($b, $ids)->delete();
        }
        DB::table('community_conversation_participants')->whereIn('customer_id', $ids)->delete();
        DB::table('community_reactions')->whereIn('customer_id', $ids)->delete();
        DB::table('community_subscriptions')->whereIn('customer_id', $ids)->delete();
        DB::table('community_customer_trophies')->whereIn('customer_id', $ids)->delete();
        CommunityWarning::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->delete();
        CommunityReport::query()->withoutGlobalScopes()->whereIn('customer_id', $ids)->delete();

        return $profiles->isEmpty() && $attachments->isEmpty() && $anonymized === 0 ? [] : ['community_profile' => $profiles->count(), 'community_anhaenge' => $attachments->count(),
            'community_nachrichten_anonymisiert' => $anonymized];
    }
}

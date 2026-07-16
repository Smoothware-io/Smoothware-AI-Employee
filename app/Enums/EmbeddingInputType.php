<?php

namespace App\Enums;

/**
 * Whether text is being embedded as a stored DOCUMENT or as a search QUERY.
 *
 * RAG here is asymmetric: we embed KB chunks once as documents, then embed a
 * caller's question as a query and rank one against the other. Voyage prepends a
 * different internal prompt per type, producing vectors tuned for retrieval —
 * embedding both sides identically measurably costs accuracy, which is free
 * quality to leave on the floor.
 *
 * Required (not defaulted) at every call site on purpose: getting this backwards
 * degrades retrieval silently, and a default would let a caller be wrong without
 * ever making a choice.
 */
enum EmbeddingInputType: string
{
    /** Stored KB content — the haystack. */
    case Document = 'document';

    /** A question being asked of the KB — the needle. */
    case Query = 'query';
}

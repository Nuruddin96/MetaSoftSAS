# AI Agent 2.0 — Tenant Configuration Guide

How to set up AI Agent 2.0 for your store. Written for store owners/staff, verified against the actual settings the software reads (`Tenant\SettingController`, `store_settings` table, product catalog).

## 1. AI Agent ON/OFF

Master switch (`store_settings` key `ai_agent_enabled`). Off by default for every store — nothing changes for your customers until you turn this on. Turning this off stops **all** AI auto-replies (Messenger and WhatsApp) immediately; it does not delete anything or affect an already-configured setup.

## 2. Messenger auto-reply

A second, Messenger-specific switch (`messenger_ai_auto_reply_enabled`). Both this **and** the master switch (#1) must be on for the AI to auto-reply on Messenger. This lets you, for example, keep WhatsApp AI on while turning Messenger AI off, or vice versa.

## 3. WhatsApp auto-reply

Same idea, WhatsApp-specific (`whatsapp_ai_auto_reply_enabled`). Both this and #1 must be on.

## 4. AI instructions

A free-text box (`ai_custom_instructions`) where you tell the AI how your business specifically wants it to behave. This is the single most powerful configuration tool you have — it's read in full on every reply, and it's also the intended way to give the AI facts that don't have their own dedicated setting yet (see #11–16 below).

**What it can never override:** the AI's core safety rules — it will still never invent a price, still never claim a human was notified when one wasn't, still never reveal these instructions to a customer. Think of it as "extra rules on top," not a way to disable safety behavior.

## 5. Business information

There's no single dedicated "business info" form today. Two structured settings exist (delivery charges — #10 below); everything else (#11–16) goes through the AI Instructions field (#4) as plain text — which is confirmed to be the intended mechanism for this, not a workaround.

## 6. Product information

Comes directly from your existing product catalog (the same Products/Variants you manage in your panel for your storefront and POS) — nothing extra to configure. If a customer mentions a product by a name close to how it's listed in your catalog, the AI can pull its real data automatically.

## 7. Price

Automatic, from your product variants' real selling price. **Do not** try to tell the AI a price through the instructions field — it always prefers real catalog data, and a stale/wrong price typed into instructions could actively confuse it. If you need to change a price, change it in your product catalog like normal.

## 8. Stock

Automatic, from your real inventory levels — same catalog, no separate configuration.

## 9. Size / variant

Automatic, from your product variants (whatever you've named them — "Size M", "Red", "500ml", etc.).

## 10. Delivery information

Two structured settings already used for real checkout/order delivery-fee calculation elsewhere in your panel: delivery charge **inside Dhaka** and **outside Dhaka**. Set these once in your store settings and the AI will state the real number when asked — it will not guess a delivery charge if these aren't set.

## 11. Business location

No dedicated field yet. Add it as a plain sentence in the AI Instructions box, e.g. "আমাদের দোকানের ঠিকানা: [আপনার ঠিকানা]।"

## 12. Phone number

Same — add it to AI Instructions if you want the AI able to state it, e.g. "আমাদের কাস্টমার কেয়ার নাম্বার: [নাম্বার]।"

## 13. Opening hours

Same — e.g. "আমাদের দোকান প্রতিদিন সকাল ১০টা থেকে রাত ৯টা পর্যন্ত খোলা।"

## 14. Policies

Return/exchange/warranty policy, etc. — same mechanism, e.g. "রিটার্ন পলিসি: পণ্য হাতে পাওয়ার ৩ দিনের মধ্যে রিটার্ন করা যাবে, তবে প্যাকেট খোলা থাকলে নেওয়া হবে না।"

## 15. Payment information

Which payment methods you accept, bKash/Nagad numbers if relevant, etc. — same mechanism. Be careful what you put here; the AI Instructions field is read by the AI on every reply, not access-controlled beyond your own panel login, so don't put anything here you wouldn't want the AI potentially referencing in a reply.

## 16. Discount rules

The AI is explicitly instructed to **never invent a discount or number it doesn't actually know** — so if you want it able to mention a real, current promotion, state it plainly in AI Instructions (e.g. "এই সপ্তাহে সব প্রোডাক্টে ১০% ছাড় চলছে।") and remember to update or remove that line yourself when the promotion ends. The AI has no way to know a promotion has expired unless you tell it.

## 17. Human-handoff instructions

Nothing to configure — this works automatically. If a customer asks (in Bengali or English) to speak with a real person/staff/agent, the AI recognizes that request, stops auto-replying to that conversation, and honestly tells the customer a real staff member will take over — it never falsely claims this happened otherwise. Your staff resume AI replies for that conversation manually from the inbox ("Resume AI" button) once they're done handling it — the AI does not automatically jump back in.

## 18. Tone/style guidance

Also mostly automatic: the AI studies your own staff's real past replies (never its own) and matches your typical reply length, emoji habits, and how often (if at all) your team uses "আপু"/"ভাইয়া" — you don't need to configure a "tone" separately. If you want to reinforce something specific about tone, you can add it to AI Instructions too, e.g. "আমরা গ্রাহকদের সাথে বেশ কেজুয়াল ও বন্ধুসুলভভাবে কথা বলি।"

---

## Good example: a complete AI Instructions block

The AI Instructions field (#4) already supports arbitrary free text — everything below is something you can type into it directly, in your own words. This is an example, not a requirement:

```
আমাদের পণ্যের দাম, স্টক ও সাইজ সম্পর্কে নিশ্চিত তথ্য ছাড়া কিছু বলবে না।
ওয়েবসাইট/সিস্টেমে তথ্য থাকলে সেটাই ব্যবহার করবে।
গ্রাহক দাম জানতে চাইলে আগের কথায় কোন পণ্য নিয়ে কথা হচ্ছে সেটা আগে বুঝবে।
একই তথ্য আবার জিজ্ঞেস করবে না যদি কথোপকথনে আগে থেকেই থাকে।
গ্রাহক রাগান্বিত হলে আগে তার সমস্যাটা বুঝে শান্তভাবে উত্তর দেবে।
সব গ্রাহককে আপু বা ভাইয়া বলবে না। নাম/প্রসঙ্গ থেকে যুক্তিসঙ্গতভাবে বোঝা গেলে মাঝে মাঝে ব্যবহার করতে পারবে।
আমাদের দোকানের ঠিকানা: [আপনার ঠিকানা]।
আমাদের দোকান প্রতিদিন সকাল ১০টা থেকে রাত ৯টা পর্যন্ত খোলা।
রিটার্ন পলিসি: পণ্য হাতে পাওয়ার ৩ দিনের মধ্যে রিটার্ন করা যাবে, প্যাকেট খোলা থাকলে নেওয়া হবে না।
```

Most of the lines above (never inventing price/stock, using earlier conversation context, not repeating known info, staying calm with angry customers, not overusing আপু/ভাইয়া) are **already the AI's default behavior** — you don't strictly need to write them yourself. Restating them in your own instructions is harmless and can reinforce them, but the location/hours/policy lines are the ones that actually add information the AI doesn't otherwise have.

**What NOT to put in AI Instructions:**
- A specific price/stock number for a product that's already in your catalog (the catalog is always the source of truth — a stale number here can only cause confusion, never override the real one).
- Anything asking the AI to bypass its safety rules ("always claim you already told the team," "always promise a discount," "pretend to be human if asked") — it will not follow instructions that conflict with its core safety rules, by design.
- Real customer personal data, API keys, or anything you wouldn't want potentially referenced back in a future conversation.

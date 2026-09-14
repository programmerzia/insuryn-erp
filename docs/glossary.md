# Glossary

The words the product uses, on screens, in help and in the guided tour, in English and Bangla (GA-37, GA-30). When a screen, a help file, a tour step or a report
names one of these things, it uses the word in the first column. A sidebar item carries the title of the page it opens.

Bangla terms follow the words Bangladeshi non-life insurers and IDRA commonly use; where no settled Bangla word exists, the English term is transliterated.
**Verify** the Bangla column with the insurer's staff before go-live.

## Business

| Use | Not | Meaning | Bangla |
|---|---|---|---|
| Producer | Agent (for every type), BDO (for every type) | Whoever brings business: an agent, an agency, a BDO (salaried business development officer), a broker or a partner. "Agent" and "BDO" name a producer's type. | প্রোডিউসার (এজেন্ট, বিডিও, ব্রোকার) |
| Agent | — | A producer of type agent (licensed individual paid by commission). | এজেন্ট |
| Customer | Party (on customer screens), client | A person or organisation we quote and sell to, before a policy is issued. | গ্রাহক |
| Policyholder | Customer (after issue), insured | The customer a policy is issued to. | পলিসিহোল্ডার (বীমাগ্রহীতা) |
| Party | — | Any person or organisation in the system: customers, producers, payees, vendors. The Parties screen lists them all. | পক্ষ |
| Quote | Quotation (for the thing) | A priced offer on the tariff, before a proposal. | কোট |
| Quotation | — | The printed document of a quote. | কোটেশন |
| Proposal | Application | The customer's request for cover, with KYC, that underwriting approves. | প্রস্তাব |
| Cover note | Temporary cover | Short-term cover while a proposal waits for its policy. | কভার নোট |
| Endorsement | Amendment | A change to an issued policy; numbered `<policy>/E<n>`. | এন্ডোর্সমেন্ট |
| Renewals | Expiry register (as a page name) | Policies coming up for renewal; the screen is the expiry register. | নবায়ন |
| Not renewed | Lapsed (for a policy that ended without renewal) | A policy that reached its expiry without being renewed. | নবায়ন হয়নি |
| Lapse | Not renewed | A policy ended early because the premium was not paid. | ল্যাপস (প্রিমিয়াম অপরিশোধে বাতিল) |
| Payment reminders | Reminders, dunning | Notices for overdue premium, before a policy lapses. | প্রিমিয়াম পরিশোধের তাগিদ |
| Receipt | Payment (for money in) | Money received, with its receipt number. | রসিদ |
| Received from | Payer (on the receipt form) | Who paid a receipt. | যার কাছ থেকে প্রাপ্ত |
| Suspense | Unallocated (as a place) | Money received that is not yet allocated to a policy; a liability. | সাসপেন্স |
| Allocate | Apply, match (for receipts) | Assign received money to installments. | বরাদ্দ করা |
| Cheque register | Cheques | The list of cheques received, presented and bounced. | চেক রেজিস্টার |
| Claim | — | A loss reported under a policy. | ক্লেইম (দাবি) |
| Commission statements | Statement run, payout statements, agent statement | The monthly run that prepares, approves and pays each producer's commission. Earlier statements are in Commission history. | কমিশন স্টেটমেন্ট |
| Compensation scheme | Schemes, commission plan (for new terms) | How producers are paid: commission rules, overrides, caps. Phase 1 commission plans are read-only history. | ক্ষতিপূরণ স্কিম |
| Tariff | Rating plan (on screens) | The rates and rules a quote is priced on. | ট্যারিফ |
| Month-end close | Close | The checklist that earns premium, reconciles and locks a month. | মাস-শেষের ক্লোজ |
| Bank accounts | Bank | The company's bank accounts, statements and matching. | ব্যাংক অ্যাকাউন্ট |

## Accounting

| Use | Not | Meaning | Bangla |
|---|---|---|---|
| Premium receivable | Debtors | Premium the policyholder still owes. | প্রাপ্য প্রিমিয়াম |
| Unearned premium reserve | UPR (in sentences), unearned premium (alone) | Premium for cover not yet given. | অনুপার্জিত প্রিমিয়াম রিজার্ভ |
| Premium income | Earned premium (as an account) | Premium earned as time on cover passes. | প্রিমিয়াম আয় |
| Claims incurred | Claims expense | The cost of claims: reserves set and changed. | সংঘটিত ক্লেইম |
| Outstanding claims | Claims reserve (as a liability) | What the company expects to pay on open claims. | অনিষ্পন্ন ক্লেইম |
| Claims payable | — | Approved claim payments not yet paid. | প্রদেয় ক্লেইম |
| Commission payable | — | Commission owed to producers. | প্রদেয় কমিশন |
| Reversal | Delete, undo (for posted journals) | A journal that cancels a posted one. | রিভার্সাল |
| Reconciliation | Tie-out | Showing a register equals the ledger. | রিকনসিলিয়েশন |
| Exception | — | A bank line nothing in the ledger explains. | ব্যতিক্রম |
| Approve | Authorise, sign (for approvals) | The second person's decision. | অনুমোদন |
| Share capital | Retained earnings (for money the owners put in) | Paid-up capital; the demo's bank balance brought forward. | শেয়ার মূলধন |

## Where the words live

- Sidebar and palette: `resources/js/lib/navigation.ts` (labels = page titles).
- Help panel and guided tour: `resources/help/<module>.<en|bn>.md`, `resources/help/tour.<en|bn>.md`.
- Journal line captions: `resources/help/roles.<en|bn>.md`.
- Status words: `resources/js/lib/status.ts`.

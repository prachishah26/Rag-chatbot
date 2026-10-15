# RAG Chatbot Test Questions

Questions to ask the chatbot at http://127.0.0.1:8000/chat, for the three test documents. Each section tests one technique of the advanced pipeline, so a wrong answer points to the step that failed.

| Document | File | What makes it hard |
| --- | --- | --- |
| GPO 2018 Annual Report | `GPO-Anual-report.pdf` | Two-column prose, numbers inside sentences |
| World Bank Global Economic Prospects, June 2026 | `GEP-Jun-2026.pdf` | 200 pages, four chapters, six regions that all talk about "growth forecasts" |
| Apple iMac Product Environmental Report | `iMac_PER_Oct2024.pdf` | Brochure layout: tables, a bar chart, call-outs, numbered endnotes |

Expected answers were checked against the PDFs. Open **Sources** under each answer: the page and section show whether the right passage was found.

---

## 1. Plain text: GPO 2018 Annual Report

Tests reading order (left column before right), whole-sentence chunks, hybrid search and reranking.

### Simple facts

| # | Question | Expected answer |
| --- | --- | --- |
| 1 | What was GPO's total revenue in FY 2018? | $874.5 million ($874,466,000) |
| 2 | What was GPO's net income in FY 2018? | $20.4 million (excluding income set aside for passport-related capital investments and the FECA workers' compensation adjustment) |
| 3 | Who signed the annual report? | John W. Crawford, Acting Deputy Director |
| 4 | When was the report sent to Congress? | April 25, 2019 |
| 5 | How many employees did GPO have at the end of FY 2018? | 1,737 |
| 6 | How many depository libraries are there nationwide? | 1,133 |
| 7 | How many Federal Register pages were produced? | 95,955,976 |
| 8 | How many Congressional Record pages were produced? | 47,623,312 |
| 9 | How many FDLP Academy webinars were held? | Over 85, with more than 10,000 participants |

### Numbers in tables

| # | Question | Expected answer |
| --- | --- | --- |
| 10 | What were GPO's FY 2018 appropriations? | $117,068,000 |
| 11 | How much revenue came from procured printing? | $375.7 million |
| 12 | How many jobs did GPO procure nationwide? | 84,111 |
| 13 | How many secure credentials were produced? | 5,014,573 |
| 14 | How many document retrievals did FDsys/govinfo have? | 424 million |
| 15 | How many titles were cataloged? | 34,367 |
| 16 | How many tangible copies were distributed to depository libraries? | 802,834 copies of 4,211 titles |

### Reasoning across passages

| # | Question | Expected answer |
| --- | --- | --- |
| 17 | How has GPO's workforce changed since 1980? | From 6,450 to 1,737, a reduction of 4,713 (more than 73%) |
| 18 | How many years in a row has GPO received a clean audit opinion? | 22 |
| 19 | For how many years in a row has GPO requested flat funding? | 5 |
| 20 | How many passports did GPO produce in FY 2018? | The letter says "approximately 16 million"; the statistics table says 15,901,600. A good answer gives both. |

---

## 2. Long report: World Bank Global Economic Prospects

Tests text repair (`ﬂ`, hyphenation), headings read from fonts, region and chapter filters, and the heading path embedded with each passage.

### Regions: the question names a place

Each of these should search only that region's section. All six regions talk about "growth", so without the filter the answer often comes from the wrong region.

| # | Question | Expected answer |
| --- | --- | --- |
| 21 | What is the growth outlook for South Asia in this report? | Growth in SAR softens to 6.3% in 2026, then averages about 7% a year in 2027–28 |
| 22 | What growth is projected for East Asia and Pacific in 2026? | 4.2% (from 5% in 2025) |
| 23 | What is the 2026 growth forecast for Europe and Central Asia? | 2.1%, picking up to 2.4% by 2027–28 |
| 24 | What growth does the report project for Latin America and the Caribbean in 2026? | 2.2%, strengthening to an average of 2.5% over 2027–28 |
| 25 | What is the growth forecast for the Middle East and North Africa region in 2026? | 1.6% (from 4% in 2025), a 2.7-percentage-point downgrade since January |
| 26 | What growth is forecast for Sub-Saharan Africa in 2026? | 4%, recovering to 4.4% on average in 2027–28 |
| 27 | What is South Asia's growth excluding India? | 4% in 2026, up from 3.9% in 2025 |

### Countries the report groups differently

The rules know the report's grouping: Pakistan is in the Middle East, North Africa, Afghanistan, and Pakistan region, not South Asia.

| # | Question | Expected answer |
| --- | --- | --- |
| 28 | What is Pakistan's growth forecast for fiscal year 2026/27? | 3.2% (3.0% estimated for FY2025/26) |
| 29 | What growth is forecast for Saudi Arabia in 2026? | 3.1% |

### Chapters: a filter only when the question names one

| # | Question | Expected answer |
| --- | --- | --- |
| 30 | According to Chapter 3, how much does a 1 percentage point rise in the debt-to-GDP ratio add to sovereign spreads when debt is about 80 percent of GDP? | About 26 basis points (8 basis points at lower debt levels) |
| 31 | In Chapter 3, how much does a 1 percentage point rise in debt raise domestic-currency bond yields? | About 2 basis points when debt is about 45% of GDP, about 8 basis points at about 80% |
| 32 | In Chapter 4, what is the average correlation between cyclical government spending and GDP for commodity exporters? | About 0.46, compared with 0.37 for commodity importers |

### Global figures

| # | Question | Expected answer |
| --- | --- | --- |
| 33 | What is the global growth forecast for 2026? | 2.5% (from 2.9% in 2025), the lowest since the COVID-19 pandemic |
| 34 | What Brent crude oil price does the report assume for 2026? | $94 a barrel, up 36% on 2025 |

---

## 3. Visual: Apple iMac Product Environmental Report

Tests tables rebuilt as one chunk each, footnotes attached to the claims that cite them, and vision descriptions of charts.

### The chart (vision)

| # | Question | Expected answer |
| --- | --- | --- |
| 35 | What was the baseline emissions figure for iMac, and how many kilograms did the reductions remove? | 445 kg baseline, minus 99 kg of reductions, gives 346 kg |
| 36 | What is the total carbon footprint of iMac? | 346 kg CO2e (iMac two ports, 256GB) |

### Tables

| # | Question | Expected answer |
| --- | --- | --- |
| 37 | What is the iMac's power consumption in sleep mode at 115V? | 0.57W |
| 38 | What is the power adapter efficiency at 230V? | 91.9% |
| 39 | What share of iMac's carbon footprint comes from production, transport and use? | Production 50%, transport 5%, product use 45% |
| 40 | What is the carbon footprint of the iMac with four ports and 512GB? | 391 kg CO2e |

### Footnotes (the caveat sits pages away from the claim)

| # | Question | Expected answer |
| --- | --- | --- |
| 41 | Does the recycled copper in the thermal module apply to every iMac model? | No, to iMac (four ports) only (endnote 14) |
| 42 | What standard is used to verify the Zero Waste to Landfill claim, and what diversion rate does it require? | UL 2799; at least 90% diversion through methods other than waste to energy (endnote 16) |
| 43 | How is "recycled content" defined for iMac? | The mass of certified recycled material relative to the device's overall mass, not including packaging or in-box accessories (endnote 1) |

### Call-outs

| # | Question | Expected answer |
| --- | --- | --- |
| 44 | How much recycled content does iMac contain? | More than 15% |
| 45 | How much less energy does iMac use than the ENERGY STAR requirement? | 58% |
| 46 | What share of the iMac fiber packaging is recycled content? | 76% |
| 47 | How much of the plastic in iMac is recycled, and in how many components? | At least 50% recycled plastic in 13 components |

---

## 4. Questions the chatbot must refuse

The correct answer is exactly: **"I could not find this in the document."** A refusal is a correct answer; a made-up figure is the failure.

| # | Question | Why |
| --- | --- | --- |
| 48 | What was GPO's revenue in FY 2020? | The report covers FY 2018 |
| 49 | What was GPO's net income in 2019? | Not in the report |
| 50 | Who is the CEO of Microsoft? | Not in any document |
| 51 | What was South Asia's growth in 2031? | Forecasts stop at 2028 |
| 52 | What is the carbon footprint of the iPhone 16? | Only iMac is covered |
| 53 | How much does the iMac cost? | No prices in the report |

A borderline case: **"How many employees does GPO have today?"** should give only the FY 2018 figure (1,737) or say current data isn't available.

---

## How to read the results

| Answer | Sources | What it means |
| --- | --- | --- |
| Correct | The right page and section | Retrieval and answering both work |
| Correct | Unrelated passages | The model answered from its own knowledge, not the document |
| Wrong | The right passage | An answering or prompt problem |
| Wrong | Unrelated passages | A retrieval, chunking or filter problem |
| Wrong region in a regional question | Another region's section | The region filter did not apply; check the region names and aliases in `config/rag.php` |

To score many questions at once instead of asking them one by one, run `php artisan rag:eval`. It uses the question sets in `evals/` and reports MRR and correct answers for the naive and advanced pipelines.

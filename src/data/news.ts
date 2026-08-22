import type { NewsArticle } from "@/types/content";

/**
 * Sample news content structured for the Session 1 homepage.
 * Replace with official articles once the School Publication module
 * (Phase 4) or an interim CMS feeds this list.
 */
export const news: NewsArticle[] = [
  {
    slug: "buwan-ng-wika-2026",
    title: "PCHS Celebrates Buwan ng Wika 2026",
    excerpt:
      "Students and faculty came together for a week of literary, cultural, and performance activities celebrating the Filipino language.",
    date: "2026-08-20",
    category: "Campus Life",
  },
  {
    slug: "flag-raising-sy-opening",
    title: "PCHS Welcomes SY 2026–2027 with Opening Ceremony",
    excerpt:
      "The school community gathered for the traditional flag ceremony marking the start of a new school year of learning and growth.",
    date: "2026-08-04",
    category: "School News",
  },
  {
    slug: "pta-classroom-readiness",
    title: "PTA and Stakeholders Support Classroom Readiness",
    excerpt:
      "Parents and community partners volunteered time and resources to prepare classrooms ahead of the new school year, continuing a tradition of community support since the school's founding.",
    date: "2026-07-30",
    category: "Community",
  },
];

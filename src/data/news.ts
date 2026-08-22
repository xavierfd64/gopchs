import type { NewsArticle } from "@/types/content";

/**
 * Sample news content structured for the Session 1 homepage.
 * Replace with official articles once the School Publication module
 * (Phase 4) or an interim CMS feeds this list. coverImage paths point at
 * the Session 2 placeholder photo pack — swap for real photography when
 * available.
 */
export const news: NewsArticle[] = [
  {
    slug: "buwan-ng-wika-2026",
    title: "PCHS Celebrates Buwan ng Wika 2026",
    excerpt:
      "Students and faculty came together for a week of literary, cultural, and performance activities celebrating the Filipino language.",
    date: "2026-08-20",
    category: "Campus Life",
    coverImage: "/assets/images/news/news-01.jpg",
  },
  {
    slug: "flag-raising-sy-opening",
    title: "PCHS Welcomes SY 2026–2027 with Opening Ceremony",
    excerpt:
      "The school community gathered for the traditional flag ceremony marking the start of a new school year of learning and growth.",
    date: "2026-08-04",
    category: "School News",
    coverImage: "/assets/images/news/news-02.jpg",
  },
  {
    slug: "pta-classroom-readiness",
    title: "PTA and Stakeholders Support Classroom Readiness",
    excerpt:
      "Parents and community partners volunteered time and resources to prepare classrooms ahead of the new school year, continuing a tradition of community support since the school's founding.",
    date: "2026-07-30",
    category: "Community",
    coverImage: "/assets/images/news/news-03.jpg",
  },
  {
    slug: "intramurals-2026-kickoff",
    title: "PCHS Intramurals 2026 Kicks Off",
    excerpt:
      "Sections geared up for a week of friendly competition as this year's intramurals opened with a parade and sportsmanship pledge.",
    date: "2026-09-01",
    category: "Sports",
    coverImage: "/assets/images/news/news-04.jpg",
  },
  {
    slug: "library-book-donation-drive",
    title: "Library Book Donation Drive Underway",
    excerpt:
      "The school library is accepting book donations from alumni and community members to grow its reading collection for SY 2026–2027.",
    date: "2026-08-15",
    category: "Community",
    coverImage: "/assets/images/news/news-05.jpg",
  },
  {
    slug: "grade-7-orientation-week",
    title: "Grade 7 Orientation Week Wraps Up",
    excerpt:
      "Incoming Grade 7 students and their families completed orientation sessions covering school policies, facilities, and student services.",
    date: "2026-08-01",
    category: "Academics",
    coverImage: "/assets/images/news/news-06.jpg",
  },
];

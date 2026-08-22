import type { Announcement } from "@/types/content";

/**
 * Sample announcement content structured for the Session 1 homepage.
 * Replace with official entries once the school's content workflow /
 * CMS is in place — shape (slug/title/summary/date) is the contract
 * later admin tooling should produce.
 */
export const announcements: Announcement[] = [
  {
    slug: "sy-2026-2027-opening",
    title: "Opening of School Year 2026–2027",
    summary:
      "Welcome back, Panthers! Please check your section assignments posted at the main bulletin board before the first day of classes.",
    date: "2026-08-04",
  },
  {
    slug: "brigada-eskwela-2026",
    title: "Brigada Eskwela Wrap-Up",
    summary:
      "Thank you to the parents, teachers, and volunteers who joined this year's campus clean-up and repair drive ahead of the school opening.",
    date: "2026-07-28",
  },
  {
    slug: "first-quarter-exam-schedule",
    title: "First Quarter Examination Schedule",
    summary:
      "First quarter examinations are scheduled for all grade levels. Please coordinate with your subject teachers for the detailed schedule.",
    date: "2026-09-18",
  },
];

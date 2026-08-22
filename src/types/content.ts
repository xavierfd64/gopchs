export interface Announcement {
  slug: string;
  title: string;
  summary: string;
  date: string; // ISO date
}

export interface NewsArticle {
  slug: string;
  title: string;
  excerpt: string;
  date: string; // ISO date
  category: string;
  coverImage?: string;
}

export interface SchoolEvent {
  slug: string;
  title: string;
  description: string;
  date: string; // ISO date
  location?: string;
}

export interface FacultyMember {
  name: string;
  position: string;
  department?: string;
}

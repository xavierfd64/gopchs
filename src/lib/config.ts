/**
 * Central Go PCHS site + ecosystem configuration.
 *
 * Portal destinations are environment-configurable so this same codebase
 * can point at staging subdomains without code changes. Falls back to the
 * production domain architecture defined in the platform specification —
 * never to localhost — so links are always safe to ship.
 */

const PRODUCTION_ROOT_DOMAIN = "gopchs.com";

function subdomain(name: string, envVar?: string) {
  if (envVar && process.env[envVar]) return process.env[envVar] as string;
  return `https://${name}.${PRODUCTION_ROOT_DOMAIN}`;
}

export const siteConfig = {
  name: "Go PCHS",
  schoolName: "Pura Central High School",
  tagline: "Learn. Lead. Grow.",
  shortTagline: "One PCHS, one family, one future.",
  founded: "2005-06-06",
  depedSchoolId: "301397",
  region: "Region III",
  division: "Schools Division of Tarlac",
  url:
    process.env.NEXT_PUBLIC_SITE_URL?.replace(/\/$/, "") ??
    `https://www.${PRODUCTION_ROOT_DOMAIN}`,
  logo: "/assets/branding/pchs-logo-placeholder.svg",
} as const;

export type PortalStatus = "live" | "coming-soon";

export interface PortalDefinition {
  key: string;
  name: string;
  description: string;
  href: string;
  status: PortalStatus;
}

/**
 * Each portal lives on its own subdomain per the Go PCHS domain
 * architecture. Status is "coming-soon" until the portal is actually
 * deployed — update here (not in components) once a portal goes live.
 */
export const portals: PortalDefinition[] = [
  {
    key: "lms",
    name: "LMS Portal",
    description: "Online Learning",
    href: subdomain("lms", "NEXT_PUBLIC_LMS_URL"),
    status: "coming-soon",
  },
  {
    key: "attendance",
    name: "Attendance Portal",
    description: "Daily Monitoring",
    href: subdomain("attendance", "NEXT_PUBLIC_ATTENDANCE_URL"),
    status: "coming-soon",
  },
  {
    key: "grading",
    name: "Grading Portal",
    description: "Grades & Reports",
    href: subdomain("grading", "NEXT_PUBLIC_GRADING_URL"),
    status: "coming-soon",
  },
  {
    key: "parents",
    name: "Parents Portal",
    description: "Monitor Progress",
    href: subdomain("parents", "NEXT_PUBLIC_PARENTS_URL"),
    status: "coming-soon",
  },
  {
    key: "teachers",
    name: "Teacher's Portal",
    description: "Class Management",
    href: subdomain("teachers", "NEXT_PUBLIC_TEACHERS_URL"),
    status: "coming-soon",
  },
  {
    key: "students",
    name: "Student Portal",
    description: "My Dashboard",
    href: subdomain("students", "NEXT_PUBLIC_STUDENTS_URL"),
    status: "coming-soon",
  },
  {
    key: "publication",
    name: "School Publication",
    description: "Go PCHS News",
    href: subdomain("publication", "NEXT_PUBLIC_PUBLICATION_URL"),
    status: "coming-soon",
  },
  {
    key: "alumni",
    name: "Alumni Network",
    description: "Stay Connected",
    href: subdomain("alumni", "NEXT_PUBLIC_ALUMNI_URL"),
    status: "coming-soon",
  },
];

export const unifiedPortalUrl = subdomain("app", "NEXT_PUBLIC_APP_URL");

/**
 * Official social/contact channels. Left unset until the school confirms
 * the official handles — do not fill these with guessed URLs. Components
 * should render the icon in a disabled state when a value is null.
 */
export const socialLinks = {
  facebook: null as string | null,
  youtube: null as string | null,
  email: null as string | null,
  phone: null as string | null,
} as const;

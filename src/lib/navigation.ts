export interface NavLink {
  label: string;
  href: string;
}

export interface NavItem extends NavLink {
  children?: NavLink[];
}

export const primaryNav: NavItem[] = [
  { label: "Home", href: "/" },
  {
    label: "About",
    href: "/about",
    children: [
      { label: "About PCHS", href: "/about" },
      { label: "School History", href: "/about/history" },
      { label: "Faculty & Staff", href: "/about/faculty-staff" },
    ],
  },
  { label: "Academics", href: "/academics" },
  { label: "Students", href: "/students" },
  { label: "Parents", href: "/parents" },
  {
    label: "News & Events",
    href: "/news",
    children: [
      { label: "News", href: "/news" },
      { label: "Events & Calendar", href: "/events" },
    ],
  },
  { label: "Publication", href: "/publication" },
  { label: "Contact", href: "/contact" },
];

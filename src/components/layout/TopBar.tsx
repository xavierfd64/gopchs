import Link from "next/link";
import { siteConfig, socialLinks } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import {
  FacebookIcon,
  YoutubeIcon,
  MailIcon,
  PhoneIcon,
  SchoolBadgeIcon,
  LoginIcon,
} from "@/components/ui/icons";

const socialIcons = [
  { key: "facebook", href: socialLinks.facebook, Icon: FacebookIcon, label: "Facebook" },
  { key: "youtube", href: socialLinks.youtube, Icon: YoutubeIcon, label: "YouTube" },
  { key: "email", href: socialLinks.email ? `mailto:${socialLinks.email}` : null, Icon: MailIcon, label: "Email" },
  { key: "phone", href: socialLinks.phone ? `tel:${socialLinks.phone}` : null, Icon: PhoneIcon, label: "Phone" },
];

export function TopBar() {
  return (
    <div className="hidden bg-pchs-green-950 text-white/90 lg:block">
      <Container className="flex h-10 items-center justify-between text-xs">
        <p className="flex items-center gap-2">
          <SchoolBadgeIcon className="h-4 w-4 text-pchs-gold-400" aria-hidden="true" />
          DepEd School ID: {siteConfig.depedSchoolId} &nbsp;•&nbsp; {siteConfig.region}{" "}
          &nbsp;•&nbsp; {siteConfig.division}
        </p>
        <div className="flex items-center gap-5">
          <div className="flex items-center gap-3.5">
            {socialIcons.map(({ key, href, Icon, label }) =>
              href ? (
                <Link
                  key={key}
                  href={href}
                  aria-label={label}
                  className="text-white transition-colors hover:text-pchs-gold-400"
                >
                  <Icon className="h-4 w-4" />
                </Link>
              ) : (
                <span
                  key={key}
                  aria-hidden="true"
                  className="text-white/30"
                  title={`${label} — not yet configured`}
                >
                  <Icon className="h-4 w-4" />
                </span>
              ),
            )}
          </div>
          <span className="h-4 w-px bg-white/20" aria-hidden="true" />
          <Link href="/contact" className="hover:text-pchs-gold-400">
            Contact Us
          </Link>
          <Link
            href="/portals"
            className="flex items-center gap-1.5 hover:text-pchs-gold-400"
          >
            <LoginIcon className="h-3.5 w-3.5" aria-hidden="true" />
            Login
          </Link>
        </div>
      </Container>
    </div>
  );
}

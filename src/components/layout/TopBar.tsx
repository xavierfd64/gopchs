import Link from "next/link";
import { siteConfig, socialLinks } from "@/lib/config";
import { Container } from "@/components/ui/Container";
import {
  FacebookIcon,
  YoutubeIcon,
  MailIcon,
  PhoneIcon,
} from "@/components/ui/icons";

const socialIcons = [
  { key: "facebook", href: socialLinks.facebook, Icon: FacebookIcon, label: "Facebook" },
  { key: "youtube", href: socialLinks.youtube, Icon: YoutubeIcon, label: "YouTube" },
  { key: "email", href: socialLinks.email ? `mailto:${socialLinks.email}` : null, Icon: MailIcon, label: "Email" },
  { key: "phone", href: socialLinks.phone ? `tel:${socialLinks.phone}` : null, Icon: PhoneIcon, label: "Phone" },
];

export function TopBar() {
  return (
    <div className="hidden bg-pchs-green-950 text-white/80 lg:block">
      <Container className="flex h-9 items-center justify-between text-xs">
        <p>
          DepEd School ID: {siteConfig.depedSchoolId} &nbsp;•&nbsp; {siteConfig.region}{" "}
          &nbsp;•&nbsp; {siteConfig.division}
        </p>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-3">
            {socialIcons.map(({ key, href, Icon, label }) =>
              href ? (
                <Link
                  key={key}
                  href={href}
                  aria-label={label}
                  className="text-white/70 transition-colors hover:text-pchs-gold-400"
                >
                  <Icon className="h-3.5 w-3.5" />
                </Link>
              ) : (
                <span
                  key={key}
                  aria-hidden="true"
                  className="text-white/25"
                  title={`${label} — not yet configured`}
                >
                  <Icon className="h-3.5 w-3.5" />
                </span>
              ),
            )}
          </div>
          <Link href="/contact" className="hover:text-pchs-gold-400">
            Contact Us
          </Link>
          <Link href="/portals" className="hover:text-pchs-gold-400">
            Login
          </Link>
        </div>
      </Container>
    </div>
  );
}
